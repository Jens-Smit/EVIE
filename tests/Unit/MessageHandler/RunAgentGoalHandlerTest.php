<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\AI\Agent\LlmRetryExecutor;
use App\AI\Agent\OrchestratorDialogService;
use App\AI\Agent\SubAgentFactory;
use App\AI\Response\FaultTolerantValidator;
use App\AI\Response\JsonResponseEnforcer;
use App\AI\Response\ResponseNormalizer;
use App\AI\Security\AuditLogger;
use App\AI\Skills\ToolDefinitionGenerator;
use App\Entity\AgentGoal;
use App\Entity\AgentHistory;
use App\Entity\UserProfile;
use App\Message\RunAgentGoalMessage;
use App\MessageHandler\RunAgentGoalHandler;
use App\Repository\AgentGoalRepository;
use App\Repository\AgentHistoryRepository;
use App\Repository\ToolDefinitionRepository;
use App\Repository\UserProfileRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

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
        return new OrchestratorDialogService(
            $this->createMock(AgentInterface::class),
            $this->createMock(ToolDefinitionGenerator::class),
            $this->createMock(SubAgentFactory::class),
            $this->createMock(EventDispatcherInterface::class),
            new NullLogger(),
            $this->createMock(PlatformInterface::class),
            $this->createMock(UrlGeneratorInterface::class),
            $this->createMock(JsonResponseEnforcer::class),
            $this->createMock(FaultTolerantValidator::class),
            $this->createMock(ResponseNormalizer::class),
            $this->createMock(ToolDefinitionRepository::class),
            new LlmRetryExecutor(new NullLogger(), maxRetries: 0, initialDelayMs: 1),
        );
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
