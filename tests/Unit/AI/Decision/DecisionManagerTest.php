<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Decision;

use App\AI\Decision\DecisionManager;
use App\Entity\DecisionLog;
use App\Repository\DecisionLogRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Vollstaendige Test-Abdeckung fuer DecisionManager (HITL-Entscheidungen).
 */
final class DecisionManagerTest extends TestCase
{
    public function testCreateDecisionPersistsAndReturnsLog(): void
    {
        $repo = $this->createMock(DecisionLogRepository::class);
        $repo->expects(self::once())->method('save')
            ->willReturnCallback(function (DecisionLog $log, bool $flush): void {
                self::assertSame('strategy_adjustment', $log->getDecisionType());
                self::assertSame('Title: Desc', $log->getDescription());
                self::assertSame('pending', $log->getStatus());
                self::assertSame('alice', $log->getMetadata()['user_identifier']);
                self::assertSame('alice', $log->getContext()['user_identifier']);
                self::assertSame('extra', $log->getContext()['k']);
            });

        $manager = new DecisionManager($repo, new NullLogger());
        $result = $manager->createDecision('alice', 'strategy_adjustment', 'Title', 'Desc', ['k' => 'extra']);

        self::assertInstanceOf(DecisionLog::class, $result);
    }

    public function testLogDecisionWithoutUser(): void
    {
        $repo = $this->createMock(DecisionLogRepository::class);
        $repo->expects(self::once())->method('save')
            ->willReturnCallback(function (DecisionLog $log): void {
                self::assertSame('tool_approval', $log->getDecisionType());
                self::assertSame('desc', $log->getDescription());
                self::assertSame(['ctx' => 1], $log->getContext());
                self::assertSame(['opt' => true], $log->getOptions());
                self::assertNull($log->getMetadata());
            });

        $manager = new DecisionManager($repo, new NullLogger());
        $result = $manager->logDecision('tool_approval', 'desc', ['ctx' => 1], ['opt' => true]);

        self::assertInstanceOf(DecisionLog::class, $result);
    }

    public function testLogDecisionWithUserSetsMetadata(): void
    {
        $repo = $this->createMock(DecisionLogRepository::class);
        $repo->expects(self::once())->method('save')
            ->willReturnCallback(function (DecisionLog $log): void {
                self::assertSame(['user_identifier' => 'bob'], $log->getMetadata());
            });

        $manager = new DecisionManager($repo, new NullLogger());
        $manager->logDecision('x', 'd', [], [], 'bob');
    }

    public function testApproveDecisionSetsApprovedState(): void
    {
        $decision = new DecisionLog();
        $decision->setMetadata(['existing' => 1]);

        $repo = $this->createMock(DecisionLogRepository::class);
        $repo->expects(self::once())->method('save')
            ->willReturnCallback(function (DecisionLog $log): void {
                self::assertSame('approved', $log->getStatus());
                self::assertSame('admin', $log->getApprovedBy());
                self::assertNotNull($log->getApprovedAt());
                $meta = $log->getMetadata();
                self::assertSame(1, $meta['existing']);
                self::assertSame('admin', $meta['approved_by']);
            });

        $manager = new DecisionManager($repo, new NullLogger());
        $manager->approveDecision($decision, 'admin');
    }

    public function testRejectDecisionSetsRejectedStateWithReason(): void
    {
        $decision = new DecisionLog();

        $repo = $this->createMock(DecisionLogRepository::class);
        $repo->expects(self::once())->method('save')
            ->willReturnCallback(function (DecisionLog $log): void {
                self::assertSame('rejected', $log->getStatus());
                self::assertSame('mod', $log->getApprovedBy());
                self::assertNotNull($log->getApprovedAt());
                $meta = $log->getMetadata();
                self::assertSame('mod', $meta['rejected_by']);
                self::assertSame('dangerous', $meta['rejection_reason']);
            });

        $manager = new DecisionManager($repo, new NullLogger());
        $manager->rejectDecision($decision, 'mod', 'dangerous');
    }

    public function testRejectDecisionWithoutReason(): void
    {
        $decision = new DecisionLog();
        $repo = $this->createMock(DecisionLogRepository::class);
        $repo->expects(self::once())->method('save');
        $manager = new DecisionManager($repo, new NullLogger());
        $manager->rejectDecision($decision, 'mod');
        self::assertSame('rejected', $decision->getStatus());
    }

    public function testGetPendingDecisionsAll(): void
    {
        $d = $this->buildDecisionLog(1, 'tool', 'd1', 'pending');
        $repo = $this->createMock(DecisionLogRepository::class);
        $repo->method('findAllPending')->willReturn([$d]);

        $manager = new DecisionManager($repo, new NullLogger());
        $result = $manager->getPendingDecisions();

        self::assertCount(1, $result);
        self::assertSame(1, $result[0]['id']);
        self::assertSame('tool', $result[0]['type']);
    }

    public function testGetPendingDecisionsFilteredByUser(): void
    {
        $alice = $this->buildDecisionLog(1, 'tool', 'a', 'pending', ['user_identifier' => 'alice']);
        $bob = $this->buildDecisionLog(2, 'tool', 'b', 'pending', ['user_identifier' => 'bob']);
        $repo = $this->createMock(DecisionLogRepository::class);
        $repo->method('findAllPending')->willReturn([$alice, $bob]);

        $manager = new DecisionManager($repo, new NullLogger());
        $result = $manager->getPendingDecisions('alice');
        self::assertCount(1, $result);
        self::assertSame(1, $result[0]['id']);
    }

    public function testGetDecisionDelegates(): void
    {
        $d = new DecisionLog();
        $repo = $this->createMock(DecisionLogRepository::class);
        $repo->method('find')->with(7)->willReturn($d);
        $manager = new DecisionManager($repo, new NullLogger());
        self::assertSame($d, $manager->getDecision(7));
    }

    public function testGetDecisionReturnsNull(): void
    {
        $repo = $this->createMock(DecisionLogRepository::class);
        $repo->method('find')->willReturn(null);
        $manager = new DecisionManager($repo, new NullLogger());
        self::assertNull($manager->getDecision(99));
    }

    public function testGetDecisionStatisticsWithoutUser(): void
    {
        $repo = $this->createMock(DecisionLogRepository::class);
        $repo->method('getStatistics')->willReturn(['total' => 5, 'pending' => 2]);
        $manager = new DecisionManager($repo, new NullLogger());
        $stats = $manager->getDecisionStatistics();
        self::assertSame(5, $stats['total']);
        self::assertSame(2, $stats['pending']);
    }

    public function testGetDecisionStatisticsWithUser(): void
    {
        $d1 = $this->buildDecisionLog(1, 'x', 'd', 'pending');
        $d2 = $this->buildDecisionLog(2, 'x', 'd', 'approved');
        $d3 = $this->buildDecisionLog(3, 'x', 'd', 'rejected');
        $repo = $this->createMock(DecisionLogRepository::class);
        $repo->method('getStatistics')->willReturn(['total' => 3]);
        $repo->method('findByUser')->with('u')->willReturn([$d1, $d2, $d3]);
        $manager = new DecisionManager($repo, new NullLogger());
        $stats = $manager->getDecisionStatistics('u');
        self::assertSame(3, $stats['user_total']);
        self::assertSame(1, $stats['user_pending']);
        self::assertSame(1, $stats['user_approved']);
        self::assertSame(1, $stats['user_rejected']);
    }

    public function testGetDecisionsByType(): void
    {
        $d = $this->buildDecisionLog(1, 'tool', 'd', 'approved');
        $d->setApprovedAt(new \DateTimeImmutable());
        $d->setApprovedBy('admin');
        $repo = $this->createMock(DecisionLogRepository::class);
        $repo->method('findByType')->with('tool')->willReturn([$d]);
        $manager = new DecisionManager($repo, new NullLogger());
        $result = $manager->getDecisionsByType('tool');
        self::assertCount(1, $result);
        self::assertSame('admin', $result[0]['approved_by']);
    }

    public function testGetDecisionsByTypeFilteredByUser(): void
    {
        $a = $this->buildDecisionLog(1, 'tool', 'a', 'pending', ['user_identifier' => 'x']);
        $b = $this->buildDecisionLog(2, 'tool', 'b', 'pending', ['user_identifier' => 'y']);
        $repo = $this->createMock(DecisionLogRepository::class);
        $repo->method('findByType')->willReturn([$a, $b]);
        $manager = new DecisionManager($repo, new NullLogger());
        $result = $manager->getDecisionsByType('tool', 'x');
        self::assertCount(1, $result);
    }

    public function testGetRecentDecisions(): void
    {
        $d = $this->buildDecisionLog(1, 't', 'd', 'pending');
        $repo = $this->createMock(DecisionLogRepository::class);
        $repo->method('findRecent')->with(5)->willReturn([$d]);
        $manager = new DecisionManager($repo, new NullLogger());
        $result = $manager->getRecentDecisions(5);
        self::assertCount(1, $result);
        self::assertSame('t', $result[0]['type']);
    }

    public function testGetRecentDecisionsFilteredByUser(): void
    {
        $a = $this->buildDecisionLog(1, 't', 'a', 'pending', ['user_identifier' => 'x']);
        $b = $this->buildDecisionLog(2, 't', 'b', 'pending', ['user_identifier' => 'y']);
        $repo = $this->createMock(DecisionLogRepository::class);
        $repo->method('findRecent')->willReturn([$a, $b]);
        $manager = new DecisionManager($repo, new NullLogger());
        self::assertCount(1, $manager->getRecentDecisions(10, 'x'));
    }

    public function testCreateToolApprovalDecision(): void
    {
        $repo = $this->createMock(DecisionLogRepository::class);
        $repo->expects(self::once())->method('save')
            ->willReturnCallback(function (DecisionLog $log): void {
                self::assertSame('tool_approval', $log->getDecisionType());
                $ctx = $log->getContext();
                self::assertSame(42, $ctx['tool_id']);
                self::assertSame('tool', $ctx['tool_name']);
                self::assertSame('req', $ctx['requester']);
                self::assertSame('approve', $log->getOptions()['action']);
            });

        $manager = new DecisionManager($repo, new NullLogger());
        $result = $manager->createToolApprovalDecision(42, 'tool', 'desc', 'req', 'u');
        self::assertInstanceOf(DecisionLog::class, $result);
    }

    public function testCreateApiAccessDecision(): void
    {
        $repo = $this->createMock(DecisionLogRepository::class);
        $repo->expects(self::once())->method('save')
            ->willReturnCallback(function (DecisionLog $log): void {
                self::assertSame('api_access', $log->getDecisionType());
                self::assertSame('GET', $log->getContext()['method']);
                self::assertTrue($log->getOptions()['allow']);
            });
        $manager = new DecisionManager($repo, new NullLogger());
        $manager->createApiAccessDecision('api', '/e', 'GET', 'u');
    }

    public function testCreateDataDeletionDecision(): void
    {
        $repo = $this->createMock(DecisionLogRepository::class);
        $repo->expects(self::once())->method('save')
            ->willReturnCallback(function (DecisionLog $log): void {
                self::assertSame('data_deletion', $log->getDecisionType());
                self::assertSame('User', $log->getContext()['data_type']);
                self::assertSame(7, $log->getContext()['record_id']);
            });
        $manager = new DecisionManager($repo, new NullLogger());
        $manager->createDataDeletionDecision('User', 7, 'u');
    }

    public function testCreateCommunicationDecision(): void
    {
        $repo = $this->createMock(DecisionLogRepository::class);
        $repo->expects(self::once())->method('save')
            ->willReturnCallback(function (DecisionLog $log): void {
                self::assertSame('communication', $log->getDecisionType());
                self::assertSame('email', $log->getContext()['type']);
                self::assertSame('a@b.test', $log->getContext()['recipient']);
                self::assertTrue($log->getOptions()['send']);
            });
        $manager = new DecisionManager($repo, new NullLogger());
        $manager->createCommunicationDecision('email', 'a@b.test', str_repeat('x', 200), 'u');
    }

    public function testHasPendingDecisionsTrue(): void
    {
        $d = $this->buildDecisionLog(1, 't', 'd', 'pending');
        $repo = $this->createMock(DecisionLogRepository::class);
        $repo->method('findAllPending')->willReturn([$d]);
        $manager = new DecisionManager($repo, new NullLogger());
        self::assertTrue($manager->hasPendingDecisions());
    }

    public function testHasPendingDecisionsFalse(): void
    {
        $repo = $this->createMock(DecisionLogRepository::class);
        $repo->method('findAllPending')->willReturn([]);
        $manager = new DecisionManager($repo, new NullLogger());
        self::assertFalse($manager->hasPendingDecisions());
    }

    public function testCountPendingDecisions(): void
    {
        $d1 = $this->buildDecisionLog(1, 't', 'a', 'pending');
        $d2 = $this->buildDecisionLog(2, 't', 'b', 'pending');
        $repo = $this->createMock(DecisionLogRepository::class);
        $repo->method('findAllPending')->willReturn([$d1, $d2]);
        $manager = new DecisionManager($repo, new NullLogger());
        self::assertSame(2, $manager->countPendingDecisions());
    }

    private function buildDecisionLog(int $id, string $type, string $desc, string $status, array $metadata = []): DecisionLog
    {
        $log = new DecisionLog();
        $log->setDecisionType($type);
        $log->setDescription($desc);
        $log->setStatus($status);
        if ($metadata !== []) {
            $log->setMetadata($metadata);
        }
        $ref = new \ReflectionClass(DecisionLog::class);
        $prop = $ref->getProperty('id');
        $prop->setValue($log, $id);

        return $log;
    }
}
