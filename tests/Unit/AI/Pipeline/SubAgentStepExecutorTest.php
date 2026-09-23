<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Pipeline;

use App\AI\Agent\LlmRetryExecutor;
use App\AI\Agent\SubAgentFactoryInterface;
use App\AI\Pipeline\Execution\ExecutionState;
use App\AI\Pipeline\Execution\SubAgentStepExecutor;
use App\AI\Pipeline\Plan\Step;
use App\AI\Pipeline\PipelineContext;
use App\Tests\Stub\StubAgent;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit-Tests fuer den SubAgentStepExecutor (Phase 5).
 *
 * Kernszenario: Der naechste Agent erhaelt das Ergebnis des vorherigen
 * Schritts als Input (input_from) — nicht nur eine abstrakte Aufgabe.
 * Damit wird aus mehreren Agenten ein Agentensystem.
 *
 * @see docs/architecture/orchestrator-pipeline.md Phase 5
 */
final class SubAgentStepExecutorTest extends TestCase
{
    public function testSupportsOnlySubagentSteps(): void
    {
        $agent = new StubAgent('ok');
        $executor = $this->buildExecutor($agent, $this->createStubFactory($agent));

        self::assertTrue($executor->supports(new Step(Step::TYPE_SUBAGENT, 'data_analyst')));
        self::assertFalse($executor->supports(new Step(Step::TYPE_TOOL, 'weather')));
        self::assertFalse($executor->supports(new Step(Step::TYPE_CLARIFY, '')));
    }

    public function testExecutePassesPreviousStepResultsAsInput(): void
    {
        // Schritt 2 (data_analyst) muss das Research-Ergebnis aus dem
        // ExecutionState als Kontext erhalten.
        $agent = new StubAgent('{"type":"data_analysis_result"}');
        $executor = $this->buildExecutor($agent, $this->createStubFactory($agent));

        $state = new ExecutionState();
        $state->set('market_research', '{"sources":["visiongastro.de"],"facts":["Gastro-Dienstleister"]}');

        $step = new Step(
            Step::TYPE_SUBAGENT,
            'data_analyst',
            ['task' => 'Analysiere die Rechercheergebnisse'],
            id: 'analysis',
            dependsOn: ['research'],
            inputFrom: ['market_research']
        );

        $result = $executor->execute($step, PipelineContext::create('Businessplan', 'u'), $state);

        self::assertSame('{"type":"data_analysis_result"}', $result);
        $content = $this->getFirstUserMessageText($agent);
        self::assertStringContainsString('Analysiere die Rechercheergebnisse', $content);
        self::assertStringContainsString('market_research', $content);
        self::assertStringContainsString('visiongastro.de', $content);
    }

    public function testExecuteUsesReasonAsTaskFallback(): void
    {
        $agent = new StubAgent('ok');
        $executor = $this->buildExecutor($agent, $this->createStubFactory($agent));

        $step = new Step(Step::TYPE_SUBAGENT, 'website_researcher', [], false, 'Recherchiere visiongastro.de', 'research');

        $result = $executor->execute($step, PipelineContext::create('x', 'u'), new ExecutionState());

        self::assertSame('ok', $result);
        $content = $this->getFirstUserMessageText($agent);
        self::assertStringContainsString('Recherchiere visiongastro.de', $content);
    }

    public function testExecuteThrowsOnEmptyResult(): void
    {
        $agent = new StubAgent('   ');
        $executor = $this->buildExecutor($agent, $this->createStubFactory($agent));
        $step = new Step(Step::TYPE_SUBAGENT, 'data_analyst', ['task' => 'Analysiere'], id: 'a');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('leeres Ergebnis');

        $executor->execute($step, PipelineContext::create('x', 'u'), new ExecutionState());
    }

    private function buildExecutor(StubAgent $agent, SubAgentFactoryInterface $factory): SubAgentStepExecutor
    {
        return new SubAgentStepExecutor(
            $factory,
            new LlmRetryExecutor(new NullLogger(), maxRetries: 1, initialDelayMs: 1),
            new NullLogger()
        );
    }

    private function createStubFactory(StubAgent $agent): SubAgentFactoryInterface
    {
        $factory = $this->createMock(SubAgentFactoryInterface::class);
        $factory->method('createByName')->willReturn($agent);

        return $factory;
    }

    private function getFirstUserMessageText(StubAgent $agent): string
    {
        $messages = $agent->getSentMessages()[0]->getMessages();
        $content = $messages[0]->getContent();
        if (is_string($content)) {
            return $content;
        }

        $text = '';
        foreach ($content as $part) {
            if ($part instanceof \Symfony\AI\Platform\Message\Content\Text) {
                $text .= $part->getText();
            }
        }

        return $text;
    }
}
