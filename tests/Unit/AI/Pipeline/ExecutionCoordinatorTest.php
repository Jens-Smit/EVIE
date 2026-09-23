<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Pipeline;

use App\AI\Agent\LlmRetryExecutor;
use App\AI\Pipeline\Capability\CapabilityDecision;
use App\AI\Pipeline\Capability\CapabilityResult;
use App\AI\Pipeline\Execution\ExecutionCoordinator;
use App\AI\Pipeline\Execution\PipelineResult;
use App\AI\Pipeline\Execution\StepExecutorInterface;
use App\AI\Pipeline\Plan\Plan;
use App\AI\Pipeline\Plan\Step;
use App\AI\Pipeline\PipelineContext;
use App\Tests\Stub\StubAgent;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Role;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Unit-Tests fuer den ExecutionCoordinator (Phase 5 + Formatierung).
 *
 * Verifiziert die vier Methoden: dialog (LLM via StubAgent), clarify
 * (Rueckfrage aus Step-Grund), awaitingApproval (HITL-Link) und execute
 * (native Agent-Loop via StubAgent). Nutzt den bestehenden StubAgent und
 * LlmRetryExecutor ohne echte API-Aufrufe.
 *
 * @see docs/architecture/orchestrator-pipeline.md Phase 5
 */
final class ExecutionCoordinatorTest extends TestCase
{
    private function buildCoordinator(AgentInterface $agent, StepExecutorInterface ...$stepExecutors): ExecutionCoordinator
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('https://tools.example/pending');

        return new ExecutionCoordinator(
            $agent,
            new LlmRetryExecutor(new NullLogger(), maxRetries: 1, initialDelayMs: 1),
            $urlGenerator,
            new NullLogger(),
            $stepExecutors
        );
    }

    public function testDialogReturnsAgentContent(): void
    {
        $agent = new StubAgent('Hallo, ich bin EVIE.');
        $coordinator = $this->buildCoordinator($agent);

        $result = $coordinator->dialog(PipelineContext::create('moin', 'u'));

        self::assertSame(PipelineResult::TYPE_DIALOG, $result->getType());
        self::assertSame('Hallo, ich bin EVIE.', $result->getContent());
    }

    public function testDialogIncludesSystemContextAsSystemMessage(): void
    {
        // Luecke 5: persistierter Konversationskontext wird als SystemMessage
        // vor die User-Nachricht gehaengt.
        $agent = new StubAgent('Kontext-Antwort');
        $coordinator = $this->buildCoordinator($agent);

        $context = PipelineContext::create('Folgefrage', 'u', '## Bisheriger Verlauf: Nutzer fragte nach Wetter');
        $result = $coordinator->dialog($context);

        self::assertSame(PipelineResult::TYPE_DIALOG, $result->getType());
        self::assertSame('Kontext-Antwort', $result->getContent());

        $bag = $agent->getSentMessages()[0];
        $messages = $bag->getMessages();
        self::assertCount(2, $messages);
        self::assertSame(Role::System, $messages[0]->getRole());
        $systemContent = $messages[0]->getContent();
        self::assertIsString($systemContent);
        self::assertStringContainsString('Bisheriger Verlauf', $systemContent);
    }

    public function testDialogFallsBackOnLlmFailure(): void
    {
        $agent = new class implements AgentInterface {
            public function call(string|\Symfony\AI\Platform\Message\MessageBag|\Symfony\AI\Platform\Message\UserMessage $input, array $options = []): \Symfony\AI\Platform\Result\ResultInterface
            {
                throw new \RuntimeException('Mistral timeout');
            }

            public function getName(): string
            {
                return 'failing';
            }
        };
        $coordinator = $this->buildCoordinator($agent);

        $result = $coordinator->dialog(PipelineContext::create('egal', 'u'));

        self::assertSame(PipelineResult::TYPE_DIALOG, $result->getType());
        self::assertStringContainsString('nicht verarbeiten', $result->getContent());
    }

    public function testClarifyUsesStepReason(): void
    {
        $coordinator = $this->buildCoordinator(new StubAgent(''));
        $plan = new Plan([new Step(Step::TYPE_CLARIFY, '', [], false, 'Bitte Ziel genauer')]);

        $result = $coordinator->clarify(PipelineContext::create('irgendwas', 'u'), $plan);

        self::assertSame(PipelineResult::TYPE_CLARIFY, $result->getType());
        self::assertStringContainsString('Bitte Ziel genauer', $result->getContent());
    }

    public function testClarifyFallbackWhenNoReason(): void
    {
        $coordinator = $this->buildCoordinator(new StubAgent(''));
        $plan = new Plan([new Step(Step::TYPE_CLARIFY, '', [], false, null)]);

        $result = $coordinator->clarify(PipelineContext::create('???', 'u'), $plan);

        self::assertStringContainsString('genauer', $result->getContent());
    }

    public function testAwaitingApprovalWithToolDefinitionId(): void
    {
        $coordinator = $this->buildCoordinator(new StubAgent(''));
        $capability = new CapabilityResult(CapabilityDecision::Pending, null, 42);

        $result = $coordinator->awaitingApproval(PipelineContext::create('api', 'u'), $capability);

        self::assertSame(PipelineResult::TYPE_AWAITING_APPROVAL, $result->getType());
        self::assertSame(42, $result->getToolDefinitionId());
        self::assertStringContainsString('pending', $result->getContent());
        self::assertStringContainsString('42', $result->getContent());
    }

    public function testAwaitingApprovalMissingWithoutToolDefinitionId(): void
    {
        $coordinator = $this->buildCoordinator(new StubAgent(''));
        $capability = new CapabilityResult(CapabilityDecision::Missing, null, null);

        $result = $coordinator->awaitingApproval(PipelineContext::create('api', 'u'), $capability);

        self::assertSame(PipelineResult::TYPE_AWAITING_APPROVAL, $result->getType());
        self::assertStringContainsString('kein passendes Werkzeug', $result->getContent());
    }

    public function testExecuteRunsStepsDeterministicallyInOrder(): void
    {
        // Phase 5: Der Plan wird Schritt fuer Schritt deterministisch
        // ausgefuehrt — nicht erneut dem Orchestrator-LLM uebergeben.
        $agent = new StubAgent('sollte nicht aufgerufen werden');
        $calls = [];
        $executor = new class ($calls) implements StepExecutorInterface {
            /** @param list<string> $calls */
            public function __construct(private array &$calls)
            {
            }

            /** @return list<string> */
            public function getCalls(): array
            {
                return $this->calls;
            }

            public function supports(Step $step): bool
            {
                return $step->getType() === Step::TYPE_TOOL;
            }

            public function execute(Step $step, PipelineContext $context, \App\AI\Pipeline\Execution\ExecutionState $state): mixed
            {
                $this->calls[] = $step->getTarget();

                return 'Ergebnis von ' . $step->getTarget();
            }
        };
        $coordinator = $this->buildCoordinator($agent, $executor);
        $plan = new Plan([
            new Step(Step::TYPE_TOOL, 'weather', ['city' => 'Berlin'], id: 'step_a'),
            new Step(Step::TYPE_TOOL, 'data_analyzer', [], id: 'step_b', dependsOn: ['step_a'], inputFrom: ['step_a']),
        ], 'Wetter analysieren');

        $result = $coordinator->execute(PipelineContext::create('Wie ist das Wetter in Berlin?', 'u'), $plan);

        self::assertSame(PipelineResult::TYPE_EXECUTED, $result->getType());
        self::assertSame(['weather', 'data_analyzer'], $calls);
        self::assertSame('Ergebnis von data_analyzer', $result->getContent());
    }

    public function testExecuteStopsWorkflowOnStepFailure(): void
    {
        // Fehlerweitergabe: schlaegt ein Schritt fehl, stoppt der
        // Workflow — kein Halluzinieren des Endergebnisses.
        $agent = new StubAgent('sollte nicht aufgerufen werden');
        $calls = [];
        $executor = new class ($calls) implements StepExecutorInterface {
            /** @param list<string> $calls */
            public function __construct(private array &$calls)
            {
            }

            /** @return list<string> */
            public function getCalls(): array
            {
                return $this->calls;
            }

            public function supports(Step $step): bool
            {
                return true;
            }

            public function execute(Step $step, PipelineContext $context, \App\AI\Pipeline\Execution\ExecutionState $state): mixed
            {
                $this->calls[] = $step->getTarget();
                if ($step->getTarget() === 'research') {
                    throw new \RuntimeException('Recherche fehlgeschlagen');
                }

                return 'ok';
            }
        };
        $coordinator = $this->buildCoordinator($agent, $executor);
        $plan = new Plan([
            new Step(Step::TYPE_SUBAGENT, 'research', [], id: 'research'),
            new Step(Step::TYPE_SUBAGENT, 'analysis', [], id: 'analysis', dependsOn: ['research']),
        ], 'Businessplan');

        $result = $coordinator->execute(PipelineContext::create('Businessplan', 'u'), $plan);

        self::assertSame(PipelineResult::TYPE_ERROR, $result->getType());
        self::assertSame(['research'], $calls);
        self::assertStringContainsString('Recherche fehlgeschlagen', $result->getContent());
    }

    public function testExecuteSortsStepsByDependencies(): void
    {
        // Der Coordinator sortiert nach depends_on, selbst wenn der
        // Plan die Schritte unsortiert liefert.
        $agent = new StubAgent('');
        $calls = [];
        $executor = new class ($calls) implements StepExecutorInterface {
            /** @param list<string> $calls */
            public function __construct(private array &$calls)
            {
            }

            /** @return list<string> */
            public function getCalls(): array
            {
                return $this->calls;
            }

            public function supports(Step $step): bool
            {
                return true;
            }

            public function execute(Step $step, PipelineContext $context, \App\AI\Pipeline\Execution\ExecutionState $state): mixed
            {
                $this->calls[] = $step->getId();

                return 'ok';
            }
        };
        $coordinator = $this->buildCoordinator($agent, $executor);
        $plan = new Plan([
            new Step(Step::TYPE_TOOL, 'strategy_document', [], id: 'business_plan', dependsOn: ['analysis']),
            new Step(Step::TYPE_TOOL, 'data_analyzer', [], id: 'analysis', dependsOn: ['research']),
            new Step(Step::TYPE_SUBAGENT, 'website_researcher', [], id: 'research'),
        ], 'Businessplan');

        $result = $coordinator->execute(PipelineContext::create('Businessplan von visiongastro.de', 'u'), $plan);

        self::assertSame(PipelineResult::TYPE_EXECUTED, $result->getType());
        self::assertSame(['research', 'analysis', 'business_plan'], $calls);
    }

    public function testExecuteRejectsUnsupportedStepType(): void
    {
        $agent = new StubAgent('');
        $coordinator = $this->buildCoordinator($agent);
        $plan = new Plan([new Step('unbekannter_typ', 'irgendwas', [], id: 'x')], 'Plan');

        $result = $coordinator->execute(PipelineContext::create('Anfrage', 'u'), $plan);

        self::assertSame(PipelineResult::TYPE_ERROR, $result->getType());
        self::assertStringContainsString('Kein StepExecutor', $result->getContent());
    }
}
