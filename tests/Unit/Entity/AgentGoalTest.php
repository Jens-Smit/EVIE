<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\AgentGoal;
use App\Entity\UserProfile;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class AgentGoalTest extends TestCase
{
    public function testConstructorDefaults(): void
    {
        $goal = new AgentGoal();
        self::assertSame('paused', $goal->getStatus());
        self::assertSame(0, $goal->getExecutionCount());
        self::assertSame([], $goal->getCapabilityConstraints());
        self::assertInstanceOf(DateTimeImmutable::class, $goal->getCreatedAt());
        self::assertNull($goal->getId());
        self::assertTrue($goal->isRequiresApproval());
        self::assertFalse($goal->isApproved());
    }

    public function testGettersAndSetters(): void
    {
        $goal = new AgentGoal();
        $goal->setUserIdentifier('tenant1')
            ->setTitle('Test Goal')
            ->setDescription('A test goal')
            ->setCronExpression('0 8 * * *')
            ->setStatus('active')
            ->setRequiresApproval(false)
            ->setIsApproved(true)
            ->setSuccessMetric('metric1')
            ->setCapabilityConstraints(['constraint1'])
            ->setLastResult(['result' => 'ok'])
            ->setExecutionCount(5)
            ->setLastEvaluationScore(0.85);

        self::assertSame('tenant1', $goal->getUserIdentifier());
        self::assertSame('Test Goal', $goal->getTitle());
        self::assertSame('A test goal', $goal->getDescription());
        self::assertSame('0 8 * * *', $goal->getCronExpression());
        self::assertSame('active', $goal->getStatus());
        self::assertFalse($goal->isRequiresApproval());
        self::assertTrue($goal->isApproved());
        self::assertSame('metric1', $goal->getSuccessMetric());
        self::assertSame(['constraint1'], $goal->getCapabilityConstraints());
        self::assertSame(['result' => 'ok'], $goal->getLastResult());
        self::assertSame(5, $goal->getExecutionCount());
        self::assertSame(0.85, $goal->getLastEvaluationScore());
    }

    public function testIncrementExecutionCount(): void
    {
        $goal = new AgentGoal();
        self::assertSame(0, $goal->getExecutionCount());
        $goal->incrementExecutionCount();
        self::assertSame(1, $goal->getExecutionCount());
        $goal->incrementExecutionCount();
        self::assertSame(2, $goal->getExecutionCount());
    }

    public function testSetLastRunAtAndNextRunAt(): void
    {
        $goal = new AgentGoal();
        $lastRun = new DateTimeImmutable('2025-01-01');
        $nextRun = new DateTimeImmutable('2025-01-02');
        $goal->setLastRunAt($lastRun);
        $goal->setNextRunAt($nextRun);
        self::assertSame($lastRun, $goal->getLastRunAt());
        self::assertSame($nextRun, $goal->getNextRunAt());
    }

    public function testSetCreatedAtAndUpdatedAt(): void
    {
        $goal = new AgentGoal();
        $created = new DateTimeImmutable('2025-01-01');
        $updated = new DateTimeImmutable('2025-01-02');
        $goal->setCreatedAt($created);
        $goal->setUpdatedAt($updated);
        self::assertSame($created, $goal->getCreatedAt());
        self::assertSame($updated, $goal->getUpdatedAt());
    }

    public function testSetLastEvaluation(): void
    {
        $goal = new AgentGoal();
        $date = new DateTimeImmutable('2025-01-01');
        $goal->setLastEvaluation($date);
        self::assertSame($date, $goal->getLastEvaluation());
    }

    public function testSetUserProfile(): void
    {
        $goal = new AgentGoal();
        $profile = new UserProfile();
        $goal->setUserProfile($profile);
        self::assertSame($profile, $goal->getUserProfile());
        self::assertNull((new AgentGoal())->getUserProfile());
    }

    public function testIsActiveAndDueWhenActiveApprovedAndDue(): void
    {
        $goal = new AgentGoal();
        $goal->setStatus('active');
        $goal->setIsApproved(true);
        $goal->setNextRunAt(new DateTimeImmutable('2020-01-01'));

        self::assertTrue($goal->isActiveAndDue());
    }

    public function testIsActiveAndDueWhenNotActive(): void
    {
        $goal = new AgentGoal();
        $goal->setStatus('paused');
        $goal->setIsApproved(true);
        $goal->setNextRunAt(new DateTimeImmutable('2020-01-01'));

        self::assertFalse($goal->isActiveAndDue());
    }

    public function testIsActiveAndDueWhenNotApproved(): void
    {
        $goal = new AgentGoal();
        $goal->setStatus('active');
        $goal->setIsApproved(false);
        $goal->setNextRunAt(new DateTimeImmutable('2020-01-01'));

        self::assertFalse($goal->isActiveAndDue());
    }

    public function testIsActiveAndDueWhenNextRunIsNull(): void
    {
        $goal = new AgentGoal();
        $goal->setStatus('active');
        $goal->setIsApproved(true);

        self::assertFalse($goal->isActiveAndDue());
    }

    public function testIsActiveAndDueWhenNextRunInFuture(): void
    {
        $goal = new AgentGoal();
        $goal->setStatus('active');
        $goal->setIsApproved(true);
        $goal->setNextRunAt(new DateTimeImmutable('2099-01-01'));

        self::assertFalse($goal->isActiveAndDue());
    }

    public function testCalculateNextRunAtWithCron(): void
    {
        $goal = new AgentGoal();
        $goal->setCronExpression('0 8 * * *');

        $nextRun = $goal->calculateNextRunAt();
        self::assertInstanceOf(DateTimeImmutable::class, $nextRun);
    }

    public function testCalculateNextRunAtWithoutCron(): void
    {
        $goal = new AgentGoal();
        self::assertNull($goal->calculateNextRunAt());
    }

    public function testCalculateNextRunAtWithInvalidCron(): void
    {
        $goal = new AgentGoal();
        $goal->setCronExpression('invalid cron expression');
        self::assertNull($goal->calculateNextRunAt());
    }
}
