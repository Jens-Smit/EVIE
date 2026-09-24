<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Pipeline;

use App\AI\Pipeline\Goal\Goal;
use App\AI\Pipeline\Intent\Intent;
use App\AI\Pipeline\PipelineContext;
use PHPUnit\Framework\TestCase;

/**
 * Unit-Tests fuer PipelineContext (P2: run_id Workflow-Tracing).
 *
 * Die run_id identifiziert einen Pipeline-Lauf eindeutig und wird von
 * allen Phasen-Logs getragen, damit ein kompletter Workflow im dev.log
 * als zusammenhaengender Trace lesbar ist (Fixplan Phase 19).
 */
final class PipelineContextTest extends TestCase
{
    public function testRunIdIsGeneratedAndUnique(): void
    {
        $a = PipelineContext::create('nachricht', 'user-1');
        $b = PipelineContext::create('nachricht', 'user-1');

        self::assertNotSame('', $a->getRunId());
        self::assertNotSame($a->getRunId(), $b->getRunId());
    }

    public function testRunIdSurvivesGoalAndIntentEnrichment(): void
    {
        // Phasen reichern den Kontext immutable an — die run_id muss im
        // gesamten Lauf identisch bleiben, damit der Trace zusammenhaengt.
        $context = PipelineContext::create('nachricht', 'user-1');
        $runId = $context->getRunId();

        $withGoal = $context->withGoal(new Goal('g', 'ziel', null, Goal::SOURCE_AD_HOC));
        self::assertSame($runId, $withGoal->getRunId());

        $withIntent = $withGoal->withIntent(Intent::Task);
        self::assertSame($runId, $withIntent->getRunId());
    }

    public function testExternalRunIdActivatesProgressAndIsPreserved(): void
    {
        // Uebergibt der Client (Dialog-Frontend) eine Session-ID, aktiviert
        // dies Live-Progress-Events auf /streaming/sessions/{runId} und die
        // ID muss identisch bleiben (Mercure-Topic = Pipeline-runId).
        $context = PipelineContext::create('nachricht', 'user-1', null, 'sess-abc');
        self::assertSame('sess-abc', $context->getRunId());
        self::assertTrue($context->isProgressEnabled());

        $withGoal = $context->withGoal(new Goal('g', 'ziel', null, Goal::SOURCE_AD_HOC));
        self::assertSame('sess-abc', $withGoal->getRunId());
        self::assertTrue($withGoal->isProgressEnabled());

        $withIntent = $withGoal->withIntent(Intent::Task);
        self::assertSame('sess-abc', $withIntent->getRunId());
        self::assertTrue($withIntent->isProgressEnabled());
    }

    public function testInternallyGeneratedRunIdKeepsProgressDisabled(): void
    {
        // Interne Aufrufer (Scheduler, RunAgentGoalHandler, StrategyManager,
        // EvaluationService) rufen run() ohne Session-ID auf: Der Lauf darf
        // keine Progress-Events publizieren.
        $context = PipelineContext::create('nachricht', 'user-1');
        self::assertFalse($context->isProgressEnabled());
    }

    public function testSystemContextSurvivesGoalAndIntentEnrichment(): void
    {
        // Regression: withGoal()/withIntent() muessen den SystemContext (z.B.
        // Konversationsverlauf) weiterreichen, sonst verliert die Pipeline
        // den Kontext ab der ersten Anreicherung.
        $context = PipelineContext::create('nachricht', 'user-1', 'kontext-aus-verlauf');
        self::assertSame('kontext-aus-verlauf', $context->getSystemContext());

        $withGoal = $context->withGoal(new Goal('g', 'ziel', null, Goal::SOURCE_AD_HOC));
        self::assertSame('kontext-aus-verlauf', $withGoal->getSystemContext());

        $withIntent = $withGoal->withIntent(Intent::Task);
        self::assertSame('kontext-aus-verlauf', $withIntent->getSystemContext());
    }
}
