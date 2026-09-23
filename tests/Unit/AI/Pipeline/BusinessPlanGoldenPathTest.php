<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Pipeline;

use App\AI\Agent\LlmRetryExecutor;
use App\AI\Pipeline\Execution\ExecutionCoordinator;
use App\AI\Pipeline\Execution\ExecutionState;
use App\AI\Pipeline\Execution\StepExecutorInterface;
use App\AI\Pipeline\Plan\Plan;
use App\AI\Pipeline\Plan\Step;
use App\AI\Pipeline\PipelineContext;
use App\Tests\Stub\StubAgent;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Recorder-StepExecutor fuer Workflow-Tests: unterstuetzt tool- und
 * subagent-Schritte, protokolliert Ausfuehrungsreihenfolge und die
 * jeweils uebergebenen input_from-Ergebnisse und liefert je Target
 * ein deterministisches Ergebnis.
 */
final class RecordingStepExecutor implements StepExecutorInterface
{
    /** @var list<string> */
    public array $executedStepIds = [];

    /** @var array<string, array<string, mixed>> */
    public array $inputsByStepId = [];

    public function supports(Step $step): bool
    {
        return $step->getType() === Step::TYPE_TOOL || $step->getType() === Step::TYPE_SUBAGENT;
    }

    public function execute(Step $step, PipelineContext $context, ExecutionState $state): mixed
    {
        $this->executedStepIds[] = $step->getId();
        $this->inputsByStepId[$step->getId()] = $state->collect($step->getInputFrom());

        return match ($step->getTarget()) {
            'website_researcher' => '{"type":"website_research_result","facts":["Gastro-Dienstleister"]}',
            'data_analyst' => '{"type":"data_analysis_result","summary":"Markt wachstumsfaehig"}',
            default => 'BUSINESSPLAN: Marktanalyse abgeschlossen, Empfehlung: Markteintritt.',
        };
    }
}

/**
 * Golden-Path-Workflow-Test (Phase 5, Businessplan-Szenario).
 *
 * Verifiziert den vollstaendigen Multi-Step-Workflow anhand des
 * Referenzfalls "Erstelle aus visiongastro.de einen Businessplan":
 *
 *   website_researcher -> data_analyst -> strategy_document
 *
 * Der echte ExecutionCoordinator fuehrt den Plan deterministisch aus:
 * jeder Schritt wird in Reihenfolge ausgefuehrt, das Ergebnis des
 * Research-Schritts wird dem data_analyst als Input uebergeben, die
 * Analyse geht an den Dokument-Schritt, und das finale Ergebnis des
 * letzten Schritts wird als Antwort geliefert. Keine Mock-LLM-Antwort
 * ersetzt die Plan-Ausfuehrung.
 *
 * @see docs/architecture/orchestrator-pipeline.md Phase 5
 */
final class BusinessPlanGoldenPathTest extends TestCase
{
    public function testResearchAnalysisSynthesisWorkflowPassesResultsBetweenSteps(): void
    {
        $executor = new RecordingStepExecutor();
        $coordinator = $this->buildCoordinator($executor);

        $plan = new Plan([
            new Step(
                Step::TYPE_SUBAGENT,
                'website_researcher',
                ['task' => 'Recherchiere https://visiongastro.de'],
                id: 'research_market',
                outputKey: 'market_research'
            ),
            new Step(
                Step::TYPE_SUBAGENT,
                'data_analyst',
                ['task' => 'Analysiere die Rechercheergebnisse'],
                id: 'analyse_market',
                dependsOn: ['research_market'],
                inputFrom: ['market_research'],
                outputKey: 'business_analysis'
            ),
            new Step(
                Step::TYPE_TOOL,
                'strategy_document',
                ['document_type' => 'business_plan'],
                id: 'create_business_plan',
                dependsOn: ['analyse_market'],
                inputFrom: ['business_analysis'],
                outputKey: 'business_plan'
            ),
        ], 'Businessplan fuer visiongastro.de erstellen');

        $result = $coordinator->execute(
            PipelineContext::create('Erstelle mir anhand der Daten von https://visiongastro.de einen Businessplan.', 'user-1'),
            $plan
        );

        // Reihenfolge: Research -> Analysis -> Dokument (deterministisch).
        self::assertSame(
            ['research_market', 'analyse_market', 'create_business_plan'],
            $executor->executedStepIds
        );

        // Research startet ohne fremde Inputs.
        self::assertSame([], $executor->inputsByStepId['research_market']);

        // data_analyst erhaelt das Research-Ergebnis als Input.
        self::assertSame(
            ['market_research' => '{"type":"website_research_result","facts":["Gastro-Dienstleister"]}'],
            $executor->inputsByStepId['analyse_market']
        );

        // strategy_document erhaelt die Analyse als Input.
        self::assertSame(
            ['business_analysis' => '{"type":"data_analysis_result","summary":"Markt wachstumsfaehig"}'],
            $executor->inputsByStepId['create_business_plan']
        );

        // Finale Antwort = Ergebnis des letzten Schritts.
        self::assertSame(
            'BUSINESSPLAN: Marktanalyse abgeschlossen, Empfehlung: Markteintritt.',
            $result->getContent()
        );
    }

    private function buildCoordinator(StepExecutorInterface $executor): ExecutionCoordinator
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('https://tools.example/pending');

        return new ExecutionCoordinator(
            new StubAgent('orchestrator sollte in Phase 5 nicht entscheiden'),
            new LlmRetryExecutor(new NullLogger(), maxRetries: 1, initialDelayMs: 1),
            $urlGenerator,
            new NullLogger(),
            $executor
        );
    }
}
