<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Pipeline;

use App\AI\Pipeline\Plan\Plan;
use App\AI\Pipeline\Plan\Step;
use PHPUnit\Framework\TestCase;

/**
 * Unit-Tests fuer Plan und Step (Phase 3).
 *
 * Verifiziert das clarify-Exit-Gate (Plan, der ausschliesslich aus einer
 * Rueckfrage besteht) und die Immutabilitaet von Step
 * (withExecutionReference liefert eine neue Instanz).
 *
 * @see docs/architecture/orchestrator-pipeline.md Phase 3
 */
final class PlanStepTest extends TestCase
{
    public function testClarificationPlanIsDetected(): void
    {
        $plan = new Plan([new Step(Step::TYPE_CLARIFY, '', [], false, 'Bitte genauer')]);

        self::assertTrue($plan->isClarification());
    }

    public function testToolPlanIsNotClarification(): void
    {
        $plan = new Plan([new Step(Step::TYPE_TOOL, 'weather', ['city' => 'Berlin'])]);

        self::assertFalse($plan->isClarification());
    }

    public function testMixedPlanIsNotClarification(): void
    {
        $plan = new Plan([
            new Step(Step::TYPE_CLARIFY, '', [], false, 'Rueckfrage'),
            new Step(Step::TYPE_TOOL, 'weather', []),
        ]);

        self::assertFalse($plan->isClarification());
    }

    public function testStepIsImmutableWithExecutionReference(): void
    {
        $step = new Step(Step::TYPE_TOOL, 'weather', ['city' => 'Berlin']);
        $reference = new \stdClass();
        $withRef = $step->withExecutionReference($reference);

        self::assertNotSame($step, $withRef);
        self::assertNull($step->getExecutionReference());
        self::assertSame($reference, $withRef->getExecutionReference());
        self::assertSame('weather', $withRef->getTarget());
        self::assertSame(['city' => 'Berlin'], $withRef->getParameters());
    }

    public function testStepNeedsCapabilityFlag(): void
    {
        $step = new Step(Step::TYPE_TOOL, 'missing_tool', [], true, 'noch nicht vorhanden');

        self::assertTrue($step->needsCapability());
        self::assertSame('noch nicht vorhanden', $step->getReason());
    }
}
