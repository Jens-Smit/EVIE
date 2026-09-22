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

        return new OrchestratorDialogService($pipeline, new TenantPlatformContext(), new NullLogger());
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

    public function testInvokeExecutesGoalHappyPathAndSavesResult(): void
    {
        $goal = new AgentGoal();
        $goal->setTitle('Ziel-Titel');
        $goal->setDescription('Ziel-Beschreibung');
        $goal->setStatus('active');
        $goal->setIsApproved(true);
        $goal->setNextRunAt(new \DateTimeImmutable('2020-01-01'));
        $goal->setExecutionCount(0);

        $userProfile = new UserProfile();
        $userProfile->setUserIdentifier('tenant1');

        $message = new RunAgentGoalMessage(7, 'tenant1', 'Ziel-Titel');
        $this->goalRepo->method('find')->willReturn($goal);
        $this->userProfileRepo->method('findOneBy')->willReturn($userProfile);

        $capturedHistory = [];
        $this->historyRepo->expects(self::once())
            ->method('save')
            ->willReturnCallback(function (AgentHistory $history, bool $flush = false) use (&$capturedHistory) {
                $capturedHistory[] = $history;

                return null;
            });

        $capturedGoals = [];
        $this->goalRepo->expects(self::exactly(2))
            ->method('save')
            ->willReturnCallback(function (AgentGoal $savedGoal, bool $flush = false) use (&$capturedGoals) {
                $capturedGoals[] = $savedGoal;

                return null;
            });

        ($this->handler)($message);

        self::assertCount(1, $capturedHistory, 'Ein AgentHistory-Eintrag muss gespeichert werden.');
        self::assertSame('autonomous_goal_execution', $capturedHistory[0]->getAction());
        $details = json_decode($capturedHistory[0]->getDetails() ?? '', true);
        self::assertSame('Ziel-Titel', $details['goal_title']);
        self::assertSame('handler-test-result', $details['result']);
        self::assertSame('autonomous', $details['execution_type']);

        self::assertCount(2, $capturedGoals, 'Ziel muss zweimal gespeichert werden (Ergebnis + Update).');
        self::assertSame('handler-test-result', $goal->getLastResult()['result']);
        self::assertSame(1, $goal->getExecutionCount());
        self::assertNotNull($goal->getLastRunAt());
        self::assertNull($goal->getNextRunAt(), 'Ohne Cron-Expression muss nextRunAt nach der Ausfuehrung null sein.');
    }

    public function testInvokeSchedulesNextRunForCronGoal(): void
    {
        $goal = new AgentGoal();
        $goal->setTitle('Cron-Ziel');
        $goal->setStatus('active');
        $goal->setIsApproved(true);
        $goal->setNextRunAt(new \DateTimeImmutable('2020-01-01'));
        $goal->setCronExpression('0 6 * * *');
        $goal->setExecutionCount(0);

        $userProfile = new UserProfile();
        $userProfile->setUserIdentifier('tenant1');

        $message = new RunAgentGoalMessage(7, 'tenant1', 'Cron-Ziel');
        $this->goalRepo->method('find')->willReturn($goal);
        $this->userProfileRepo->method('findOneBy')->willReturn($userProfile);
        $this->historyRepo->method('save');
        $this->goalRepo->method('save');

        ($this->handler)($message);

        self::assertNotNull($goal->getNextRunAt(), 'Mit Cron-Expression muss nextRunAt neu berechnet werden.');
        self::assertGreaterThan(new \DateTimeImmutable(), $goal->getNextRunAt());
    }

    public function testInvokeHandlesSetupTaskPlanStepsAndStringConstraints(): void
    {
        $goal = new AgentGoal();
        $goal->setTitle('Setup-Ziel');
        $goal->setStatus('active');
        $goal->setIsApproved(true);
        $goal->setNextRunAt(new \DateTimeImmutable('2020-01-01'));
        $goal->setExecutionCount(0);

        $userProfile = new UserProfile();
        $userProfile->setUserIdentifier('tenant1');

        $message = new RunAgentGoalMessage(9, 'tenant1', 'Setup-Ziel', [
            ['type' => 'tool', 'target' => 'excel_parser', 'reason' => 'Datenbasis erstellen'],
            ['type' => 'document', 'target' => 'strategie.md', 'reason' => ''],
        ]);
        $this->goalRepo->method('find')->willReturn($goal);
        $this->userProfileRepo->method('findOneBy')->willReturn($userProfile);
        $this->historyRepo->method('save');
        $this->goalRepo->method('save');

        ($this->handler)($message);

        self::assertSame('handler-test-result', $goal->getLastResult()['result']);
        self::assertSame(1, $goal->getExecutionCount());
    }

    public function testInvokeHandlesStringCapabilityConstraints(): void
    {
        $stringGoal = new AgentGoal();
        $stringGoal->setTitle('Constraint-Ziel');
        $stringGoal->setStatus('active');
        $stringGoal->setIsApproved(true);
        $stringGoal->setNextRunAt(new \DateTimeImmutable('2020-01-01'));
        $stringGoal->setExecutionCount(0);

        $userProfile = new UserProfile();
        $userProfile->setUserIdentifier('tenant1');

        $stringMessage = new RunAgentGoalMessage(10, 'tenant1', 'Constraint-Ziel', ['Nur lesen']);
        $this->goalRepo->method('find')->willReturn($stringGoal);
        $this->userProfileRepo->method('findOneBy')->willReturn($userProfile);
        $this->historyRepo->method('save');
        $this->goalRepo->method('save');

        ($this->handler)($stringMessage);

        self::assertSame('handler-test-result', $stringGoal->getLastResult()['result']);
    }

    public function testInvokeLogsFailedAuditEntryOnOrchestratorException(): void
    {
        $goal = new AgentGoal();
        $goal->setTitle('Fehler-Ziel');
        $goal->setStatus('active');
        $goal->setIsApproved(true);
        $goal->setNextRunAt(new \DateTimeImmutable('2020-01-01'));

        $userProfile = new UserProfile();
        $userProfile->setUserIdentifier('tenant1');

        $message = new RunAgentGoalMessage(7, 'tenant1', 'Fehler-Ziel');
        $this->goalRepo->method('find')->willReturn($goal);
        $this->userProfileRepo->method('findOneBy')->willReturn($userProfile);

        $failingPipeline = $this->createMock(PipelineInterface::class);
        $failingPipeline->method('run')->willThrowException(new \RuntimeException('LLM nicht erreichbar'));
        $failingOrchestrator = new OrchestratorDialogService($failingPipeline, new TenantPlatformContext(), new NullLogger());
        $handler = new RunAgentGoalHandler(
            $failingOrchestrator,
            $this->goalRepo,
            $this->historyRepo,
            $this->userProfileRepo,
            $this->auditLogger,
            new NullLogger()
        );

        $this->historyRepo->expects(self::never())->method('save');
        $this->goalRepo->expects(self::never())->method('save');

        ($handler)($message);

        self::assertNull($goal->getLastResult(), 'Bei Fehler darf kein Ergebnis im Ziel gespeichert werden.');
    }

    private function createUserProfileWithId(string $identifier): UserProfile
    {
        $profile = new UserProfile();
        $profile->setUserIdentifier($identifier);

        return $profile;
    }
}
