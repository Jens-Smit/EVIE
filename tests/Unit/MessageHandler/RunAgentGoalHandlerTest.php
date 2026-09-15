<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\AI\Agent\OrchestratorDialogService;
use App\AI\Platform\TenantPlatformContext;
use App\AI\Pipeline\Execution\PipelineResult;
use App\AI\Pipeline\PipelineInterface;
use App\AI\Security\AuditLogger;
use App\Entity\AgentGoal;
use App\Entity\AgentHistory;
use App\Entity\UserProfile;
use App\Message\RunAgentGoalMessage;
use App\MessageHandler\RunAgentGoalHandler;
use App\Repository\AgentGoalRepository;
use App\Repository\AgentHistoryRepository;
use App\Repository\UserProfileRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Unit-Tests für RunAgentGoalHandler (autonome Ziel-Ausführung).
 */
final class RunAgentGoalHandlerTest extends TestCase
{
    private AgentGoalRepository&MockObject $goalRepo;
    private AgentHistoryRepository&MockObject $historyRepo;
    private UserProfileRepository&MockObject $userProfileRepo;
    private AuditLogger $auditLogger;
    private OrchestratorDialogService $orchestrator;
    private RunAgentGoalHandler $handler;

    protected function setUp(): void
    {
        $this->goalRepo = $this->createMock(AgentGoalRepository::class);
        $this->historyRepo = $this->createMock(AgentHistoryRepository::class);
        $this->userProfileRepo = $this->createMock(UserProfileRepository::class);

        $requestStack = new RequestStack();
        $this->auditLogger = new AuditLogger(
            $this->createMockAuditLogRepository(),
            $requestStack
        );

        $this->orchestrator = $this->buildOrchestrator();
        $this->handler = new RunAgentGoalHandler(
            $this->orchestrator,
            $this->goalRepo,
            $this->historyRepo,
            $this->userProfileRepo,
            $this->auditLogger,
            new NullLogger()
        );
    }

    private function buildOrchestrator(): OrchestratorDialogService
    {
        $pipeline = $this->createMock(PipelineInterface::class);
        $pipeline->method('run')->willReturn(
            new PipelineResult(PipelineResult::TYPE_EXECUTED, 'handler-test-result')
        );

        return new OrchestratorDialogService($pipeline, new TenantPlatformContext());
    }

    private function createMockAuditLogRepository(): \App\Repository\AuditLogRepository&MockObject
    {
        $repo = $this->createMock(\App\Repository\AuditLogRepository::class);
        $repo->method('log')->willReturn(new \App\Entity\AuditLog());
        return $repo;
    }

    public function testInvokeReturnsWhenGoalNotFound(): void
    {
        $message = new RunAgentGoalMessage(999, 'tenant1', 'Test Goal');

        $this->goalRepo->method('find')->willReturn(null);
        $this->historyRepo->expects(self::never())->method('save');

        ($this->handler)($message);
    }

    public function testInvokeReturnsWhenGoalNotActiveAndDue(): void
    {
        $goal = new AgentGoal();
        $goal->setStatus('paused');
        $goal->setIsApproved(false);

        $message = new RunAgentGoalMessage(1, 'tenant1', 'Test Goal');

        $this->goalRepo->method('find')->willReturn($goal);
        $this->historyRepo->expects(self::never())->method('save');

        ($this->handler)($message);
    }

    public function testInvokeReturnsWhenUserProfileNotFound(): void
    {
        $goal = new AgentGoal();
        $goal->setStatus('active');
        $goal->setIsApproved(true);
        $goal->setNextRunAt(new \DateTimeImmutable('2020-01-01'));

        $message = new RunAgentGoalMessage(1, 'tenant1', 'Test Goal');

        $this->goalRepo->method('find')->willReturn($goal);
        $this->userProfileRepo->method('findOneBy')->willReturn(null);
        $this->historyRepo->expects(self::never())->method('save');

        ($this->handler)($message);
    }

    private function createUserProfileWithId(string $identifier): UserProfile
    {
        $profile = new UserProfile();
        $profile->setUserIdentifier($identifier);

        return $profile;
    }
}
