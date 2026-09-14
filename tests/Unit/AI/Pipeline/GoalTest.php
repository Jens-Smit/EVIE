<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Pipeline;

use App\AI\Pipeline\Goal\Goal;
use PHPUnit\Framework\TestCase;

/**
 * Unit-Tests fuer das Goal-Value-Object (Phase 1).
 *
 * Goal ist rein deskriptiv und enthaelt keine Logik, die eine Capability
 * oder HITL ausloesen koennte. Die Quellen-Konstanten sichern die
 * Unterscheidung zwischen AgentGoal (autonom) und ad-hoc.
 *
 * @see docs/architecture/orchestrator-pipeline.md Phase 1
 */
final class GoalTest extends TestCase
{
    public function testHoldsAllFieldsWithAdHocDefault(): void
    {
        $goal = new Goal('g-1', '100k EUR Jahresumsatz', '>= 100000 EUR');

        self::assertSame('g-1', $goal->getIdentifier());
        self::assertSame('100k EUR Jahresumsatz', $goal->getDescription());
        self::assertSame('>= 100000 EUR', $goal->getSuccessMetric());
        self::assertSame(Goal::SOURCE_AD_HOC, $goal->getSource());
    }

    public function testAgentGoalSourceIsExplicit(): void
    {
        $goal = new Goal('g-2', 'Strategie', null, Goal::SOURCE_AGENT_GOAL);

        self::assertNull($goal->getSuccessMetric());
        self::assertSame(Goal::SOURCE_AGENT_GOAL, $goal->getSource());
    }
}
