<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\GoalEvaluation;
use App\Entity\AgentGoal;
use App\Entity\AgentHistory;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Unit-Tests fuer GoalEvaluation-Entity.
 */
final class GoalEvaluationTest extends TestCase
{
    public function testGettersAndSetters(): void
    {
        $evaluation = new GoalEvaluation();
        $date = new DateTimeImmutable('2025-01-01');
        $goal = $this->createMock(AgentGoal::class);
        $goal->method('getId')->willReturn(7);
        $history = $this->createMock(AgentHistory::class);
        $history->method('getId')->willReturn(99);

        $evaluation->setGoal($goal);
        $evaluation
            ->setAgentHistoryId(99)
            ->setSuccess(true)
            ->setScore(0.92)
            ->setFeedback('Good work')
            ->setEvaluationDetails(['criteria' => 'accuracy'])
            ->setEvaluatedBy('user@example.com')
            ->setCreatedAt($date)
            ->setAgentHistory($history);

        self::assertSame(7, $evaluation->getGoalId());
        self::assertSame(99, $evaluation->getAgentHistoryId());
        self::assertTrue($evaluation->isSuccess());
        self::assertSame(0.92, $evaluation->getScore());
        self::assertSame('Good work', $evaluation->getFeedback());
        self::assertSame('{"criteria":"accuracy"}', $evaluation->getEvaluationDetails());
        self::assertSame('user@example.com', $evaluation->getEvaluatedBy());
        self::assertSame($date, $evaluation->getCreatedAt());
        self::assertSame($goal, $evaluation->getGoal());
        self::assertSame($history, $evaluation->getAgentHistory());
    }

    public function testDefaults(): void
    {
        $evaluation = new GoalEvaluation();
        $evaluation->setGoalId(1);
        $evaluation->setSuccess(false);

        self::assertNull($evaluation->getId());
        self::assertNull($evaluation->getAgentHistoryId());
        self::assertFalse($evaluation->isSuccess());
        self::assertNull($evaluation->getScore());
        self::assertNull($evaluation->getFeedback());
        self::assertNull($evaluation->getEvaluationDetails());
        self::assertNull($evaluation->getEvaluatedBy());
        self::assertNull($evaluation->getAgentHistory());
    }

    public function testEvaluationDetailsAcceptsString(): void
    {
        $evaluation = new GoalEvaluation();
        $evaluation->setEvaluationDetails('string details');

        self::assertSame('string details', $evaluation->getEvaluationDetails());
    }

    public function testEvaluationDetailsAcceptsNull(): void
    {
        $evaluation = new GoalEvaluation();
        $evaluation->setEvaluationDetails(null);

        self::assertNull($evaluation->getEvaluationDetails());
    }
}
