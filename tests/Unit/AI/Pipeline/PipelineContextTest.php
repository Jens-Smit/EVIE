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
}
