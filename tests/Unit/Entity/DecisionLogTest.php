<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\DecisionLog;
use App\Entity\UserProfile;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class DecisionLogTest extends TestCase
{
    public function testGettersAndSetters(): void
    {
        $user = new UserProfile();
        $log = new DecisionLog();
        $log->setDecision('Test Decision')
            ->setDecisionType('tool_approval')
            ->setDescription('Should we run this tool?')
            ->setContext(['tool_id' => 1])
            ->setOptions(['approve', 'reject'])
            ->setMetadata(['priority' => 'high'])
            ->setStatus('pending')
            ->setUser($user);

        self::assertSame('Test Decision', $log->getDecision());
        self::assertSame('tool_approval', $log->getDecisionType());
        self::assertSame('Should we run this tool?', $log->getDescription());
        self::assertSame(['tool_id' => 1], $log->getContext());
        self::assertSame(['approve', 'reject'], $log->getOptions());
        self::assertSame(['priority' => 'high'], $log->getMetadata());
        self::assertSame('pending', $log->getStatus());
        self::assertSame($user, $log->getUser());
        self::assertInstanceOf(DateTimeImmutable::class, $log->getCreatedAt());
        self::assertNull($log->getApprovedAt());
        self::assertNull($log->getApprovedBy());
        self::assertNull($log->getId());
    }

    public function testApprovalFields(): void
    {
        $log = new DecisionLog();
        $date = new DateTimeImmutable('2025-06-01 12:00:00');
        $log->setApprovedAt($date)
            ->setApprovedBy('admin@evie.test');

        self::assertSame($date, $log->getApprovedAt());
        self::assertSame('admin@evie.test', $log->getApprovedBy());
    }

    public function testStatusHelpersPending(): void
    {
        $log = new DecisionLog();
        $log->setStatus('pending');
        self::assertTrue($log->isPending());
        self::assertFalse($log->isApproved());
        self::assertFalse($log->isRejected());
    }

    public function testStatusHelpersApproved(): void
    {
        $log = new DecisionLog();
        $log->setStatus('approved');
        self::assertTrue($log->isApproved());
        self::assertFalse($log->isPending());
        self::assertFalse($log->isRejected());
    }

    public function testStatusHelpersRejected(): void
    {
        $log = new DecisionLog();
        $log->setStatus('rejected');
        self::assertTrue($log->isRejected());
        self::assertFalse($log->isPending());
        self::assertFalse($log->isApproved());
    }

    public function testSetCreatedAt(): void
    {
        $log = new DecisionLog();
        $date = new DateTimeImmutable('2025-01-01');
        $log->setCreatedAt($date);
        self::assertSame($date, $log->getCreatedAt());
    }

    public function testDefaultStatusIsPending(): void
    {
        $log = new DecisionLog();
        self::assertSame('pending', $log->getStatus());
        self::assertTrue($log->isPending());
    }
}
