<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Workflow;

use App\AI\Security\AuditLogger;
use App\AI\Skills\Tool\DynamicTool;
use App\AI\Workflow\HitlWorkflowManager;
use App\AI\Workflow\PendingExecution;
use App\Entity\User;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Vollstaendige Test-Abdeckung fuer HitlWorkflowManager
 * (blockExecution/approveExecution/rejectExecution/getPendingExecutions).
 */
final class HitlWorkflowManagerTest extends TestCase
{
    public function testBlockExecutionStoresAndAudits(): void
    {
        $audit = $this->createMock(AuditLogger::class);
        $audit->expects(self::once())
            ->method('log')
            ->with(
                'hitl_execution_blocked',
                self::isInstanceOf(User::class),
                null,
                'ToolDefinition',
                self::callback(fn (array $c) => $c['execution_id'] === 'exec-1' && $c['tool_name'] === 'tool-a'),
                'warning',
                null
            )
            ->willReturn($this->createStub(\App\Entity\AuditLog::class));

        $manager = new HitlWorkflowManager($audit, new NullLogger());
        $pending = $manager->blockExecution('exec-1', $this->buildTool('tool-a'), ['x' => 1], $this->buildUser(), 'orig');

        self::assertInstanceOf(PendingExecution::class, $pending);
        self::assertSame('exec-1', $pending->getExecutionId());
        self::assertSame('orig', $pending->getOriginalRequest());
    }

    public function testApproveExecutionUnknownReturnsNull(): void
    {
        $audit = $this->createMock(AuditLogger::class);
        $manager = new HitlWorkflowManager($audit, new NullLogger());

        self::assertNull($manager->approveExecution('missing', $this->buildUser()));
    }

    public function testApproveExecutionSuccessExecutesAndRemovesPending(): void
    {
        $audit = $this->createMock(AuditLogger::class);
        $audit->expects(self::once())
            ->method('logHitlDecision')
            ->with(0, 'tool-a', self::isInstanceOf(User::class), 'approved', 'ok')
            ->willReturn($this->createStub(\App\Entity\AuditLog::class));

        $manager = new HitlWorkflowManager($audit, new NullLogger());
        $manager->blockExecution('exec-1', $this->buildTool('tool-a'), ['x' => 1], $this->buildUser(), 'orig');

        $result = $manager->approveExecution('exec-1', $this->buildUser(), 'ok');

        self::assertNotNull($result);
        self::assertTrue($result->isSuccess());
        self::assertSame('orig', $result->getOriginalRequest());
        self::assertNull($result->getError());
        self::assertNull($manager->getPendingExecution('exec-1'));
    }

    public function testApproveExecutionToolFailureReturnsFailedResult(): void
    {
        $audit = $this->createMock(AuditLogger::class);
        $audit->expects(self::once())
            ->method('logHitlDecision')
            ->willReturn($this->createStub(\App\Entity\AuditLog::class));

        $tool = $this->getMockBuilder(DynamicTool::class)
            ->disableOriginalConstructor()
 ->onlyMethods(['getName'])
            ->getMock();
        $tool->method('getName')->willThrowException(new \RuntimeException('boom'));

        $manager = new HitlWorkflowManager($audit, new NullLogger());

        $pending = new PendingExecution('exec-1', $tool, ['x' => 1], $this->buildUser(), 'orig');
        $ref = new \ReflectionClass($manager);
        $prop = $ref->getProperty('pendingExecutions');
        $prop->setValue($manager, ['exec-1' => $pending]);

        $result = $manager->approveExecution('exec-1', $this->buildUser());

        self::assertNotNull($result);
        self::assertFalse($result->isSuccess());
        self::assertSame('boom', $result->getError());
    }

    public function testRejectExecutionUnknownReturnsFalse(): void
    {
        $audit = $this->createMock(AuditLogger::class);
        $manager = new HitlWorkflowManager($audit, new NullLogger());

        self::assertFalse($manager->rejectExecution('missing', $this->buildUser(), 'no'));
    }

    public function testRejectExecutionAuditsAndRemoves(): void
    {
        $audit = $this->createMock(AuditLogger::class);
        $audit->expects(self::once())
            ->method('logHitlDecision')
            ->with(0, 'tool-a', self::isInstanceOf(User::class), 'rejected', 'danger')
            ->willReturn($this->createStub(\App\Entity\AuditLog::class));

        $manager = new HitlWorkflowManager($audit, new NullLogger());
        $manager->blockExecution('exec-1', $this->buildTool('tool-a'), [], $this->buildUser(), 'orig');

        self::assertTrue($manager->rejectExecution('exec-1', $this->buildUser(), 'danger'));
        self::assertNull($manager->getPendingExecution('exec-1'));
    }

    public function testGetPendingExecutionsReturnsAll(): void
    {
        $audit = $this->createMock(AuditLogger::class);
        $audit->method('log')->willReturn($this->createStub(\App\Entity\AuditLog::class));
        $manager = new HitlWorkflowManager($audit, new NullLogger());

        $manager->blockExecution('e1', $this->buildTool('t1'), [], $this->buildUser(), 'r1');
        $manager->blockExecution('e2', $this->buildTool('t2'), [], $this->buildUser(), 'r2');

        $all = $manager->getPendingExecutions();
        self::assertCount(2, $all);
        self::assertArrayHasKey('e1', $all);
        self::assertArrayHasKey('e2', $all);
    }

    public function testGetPendingExecutionReturnsNullIfMissing(): void
    {
        $audit = $this->createMock(AuditLogger::class);
        $manager = new HitlWorkflowManager($audit, new NullLogger());
        self::assertNull($manager->getPendingExecution('nope'));
    }

    private function buildTool(string $name): DynamicTool
    {
        return new DynamicTool($name);
    }

    private function buildUser(): User
    {
        $user = new User();
        $user->setEmail('u@e.test');
        return $user;
    }
}
