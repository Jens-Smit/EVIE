<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Pipeline;

use App\AI\Pipeline\Execution\ExecutionState;
use App\AI\Pipeline\Execution\ToolStepExecutor;
use App\AI\Pipeline\Plan\Step;
use App\AI\Pipeline\PipelineContext;
use App\AI\Skills\Tool\DynamicToolExecutor;
use App\AI\Skills\Tool\ToolRegistry;
use App\Repository\ToolDefinitionRepository;
use App\Tests\Stub\InMemoryTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit-Tests fuer den ToolStepExecutor (Phase 5).
 *
 * Verifiziert: statische Tool-Ausfuehrung via ToolRegistry, Input-
 * Weitergabe aus dem ExecutionState, dynamische Tool-Definitionen und
 * Fehler- bzw. Aufloesungs-Fehlverhalten.
 *
 * @see docs/architecture/orchestrator-pipeline.md Phase 5
 */
final class ToolStepExecutorTest extends TestCase
{
    public function testSupportsOnlyToolSteps(): void
    {
        $executor = $this->buildExecutor($this->createRegistry());

        self::assertTrue($executor->supports(new Step(Step::TYPE_TOOL, 'weather')));
        self::assertFalse($executor->supports(new Step(Step::TYPE_SUBAGENT, 'data_analyst')));
    }

    public function testExecutesStaticToolFromRegistry(): void
    {
        $tool = new InMemoryTool('weather', 'Liefert Wetterdaten', ['city' => 'Berlin']);
        $executor = $this->buildExecutor($this->createRegistry($tool));

        $step = new Step(Step::TYPE_TOOL, 'weather', ['city' => 'Berlin'], id: 'wetter');
        $result = $executor->execute($step, PipelineContext::create('Wetter?', 'u'), new ExecutionState());

        self::assertSame(['city' => 'Berlin'], $result);
    }

    public function testMergesInputFromStateIntoParameters(): void
    {
        // Der Tool-Schritt erhaelt die Ergebnisse seiner input_from-Schritte.
        $tool = new InMemoryTool('strategy_document', 'Erstellt Dokument', []);
        $executor = $this->buildExecutor($this->createRegistry($tool));

        $state = new ExecutionState();
        $state->set('business_analysis', ['summary' => 'Markt wachstumsfaehig']);

        $step = new Step(
            Step::TYPE_TOOL,
            'strategy_document',
            ['document_type' => 'business_plan'],
            id: 'plan',
            inputFrom: ['business_analysis']
        );
        $result = $executor->execute($step, PipelineContext::create('Businessplan', 'u'), $state);

        self::assertSame(
            ['business_analysis' => ['summary' => 'Markt wachstumsfaehig']],
            $result['input_from']
        );
        self::assertSame('business_plan', $result['document_type']);
    }

    public function testThrowsWhenToolNotResolvable(): void
    {
        $executor = $this->buildExecutor($this->createRegistry());
        $step = new Step(Step::TYPE_TOOL, 'nicht_vorhanden', [], id: 'x');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('konnte nicht aufgeloest werden');

        $executor->execute($step, PipelineContext::create('x', 'u'), new ExecutionState());
    }

    private function createRegistry(object ...$tools): ToolRegistry
    {
        return new ToolRegistry(array_values($tools));
    }

    private function buildExecutor(ToolRegistry $registry): ToolStepExecutor
    {
        $repo = $this->createMock(ToolDefinitionRepository::class);
        $repo->method('findOneByNameForUser')->willReturn(null);

        $dynamicExecutor = $this->createMock(DynamicToolExecutor::class);

        return new ToolStepExecutor($registry, $repo, $this->createDynamicToolFactory(), $dynamicExecutor, new NullLogger());
    }

    private function createDynamicToolFactory(): \App\AI\Skills\Tool\DynamicToolFactory
    {
        return $this->createMock(\App\AI\Skills\Tool\DynamicToolFactory::class);
    }
}
