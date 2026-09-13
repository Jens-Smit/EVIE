<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Pipeline;

use App\AI\Agent\LlmRetryExecutor;
use App\AI\Pipeline\Capability\CapabilityDecision;
use App\AI\Pipeline\Capability\CapabilityResult;
use App\AI\Pipeline\Execution\ExecutionCoordinator;
use App\AI\Pipeline\Execution\PipelineResult;
use App\AI\Pipeline\Plan\Plan;
use App\AI\Pipeline\Plan\Step;
use App\AI\Pipeline\PipelineContext;
use App\Tests\Stub\StubAgent;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Agent\AgentInterface;
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
    private function buildCoordinator(AgentInterface $agent): ExecutionCoordinator
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('https://tools.example/pending');

        return new ExecutionCoordinator(
            $agent,
            new LlmRetryExecutor(new NullLogger(), maxRetries: 1, initialDelayMs: 1),
            $urlGenerator,
            new NullLogger()
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

    public function testExecuteReturnsAgentContent(): void
    {
        $agent = new StubAgent('Wetter in Berlin: 24 Grad.');
        $coordinator = $this->buildCoordinator($agent);
        $plan = new Plan([new Step(Step::TYPE_TOOL, 'weather', ['city' => 'Berlin'])], 'Wetter abrufen');

        $result = $coordinator->execute(PipelineContext::create('Wie ist das Wetter in Berlin?', 'u'), $plan);

        self::assertSame(PipelineResult::TYPE_EXECUTED, $result->getType());
        self::assertSame('Wetter in Berlin: 24 Grad.', $result->getContent());
    }

    public function testExecuteFallsBackOnError(): void
    {
        $agent = new class implements AgentInterface {
            public function call(string|\Symfony\AI\Platform\Message\MessageBag|\Symfony\AI\Platform\Message\UserMessage $input, array $options = []): \Symfony\AI\Platform\Result\ResultInterface
            {
                throw new \RuntimeException('Executor kaputt');
            }

            public function getName(): string
            {
                return 'failing';
            }
        };
        $coordinator = $this->buildCoordinator($agent);
        $plan = new Plan([new Step(Step::TYPE_TOOL, 'weather', [])], 'Wetter');

        $result = $coordinator->execute(PipelineContext::create('Wetter', 'u'), $plan);

        self::assertSame(PipelineResult::TYPE_ERROR, $result->getType());
        self::assertStringContainsString('Executor kaputt', $result->getContent());
    }
}
