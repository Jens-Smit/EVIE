<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Strategy;

use App\AI\Agent\LlmRetryExecutor;
use App\AI\Agent\OrchestratorDialogService;
use App\AI\Agent\SubAgentFactory;
use App\AI\Response\FaultTolerantValidator;
use App\AI\Response\JsonResponseEnforcer;
use App\AI\Response\ResponseNormalizer;
use App\AI\Skills\ToolDefinitionGenerator;
use App\AI\Strategy\EvaluationService;
use App\Entity\AgentGoal;
use App\Entity\AgentHistory;
use App\Entity\GoalEvaluation;
use App\Entity\UserProfile;
use App\Repository\AgentGoalRepository;
use App\Repository\GoalEvaluationRepository;
use App\Repository\ToolDefinitionRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Unit-Tests für EvaluationService (Ziel-Evaluation mit generischer und LLM-gestützter Bewertung).
 */
final class EvaluationServiceTest extends TestCase
{
    private GoalEvaluationRepository&MockObject $evaluationRepo;
    private AgentGoalRepository&MockObject $goalRepo;
    private OrchestratorDialogService $orchestrator;
    private EvaluationService $service;

    protected function setUp(): void
    {
        $this->evaluationRepo = $this->createMock(GoalEvaluationRepository::class);
        $this->goalRepo = $this->createMock(AgentGoalRepository::class);
        $this->orchestrator = $this->buildOrchestrator();
        $this->service = new EvaluationService(
            $this->evaluationRepo,
            $this->goalRepo,
            $this->orchestrator,
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
            $this->createMock(\Symfony\Component\Routing\Generator\UrlGeneratorInterface::class),
            $this->createMock(JsonResponseEnforcer::class),
            $this->createMock(FaultTolerantValidator::class),
            $this->createMock(ResponseNormalizer::class),
            $this->createMock(ToolDefinitionRepository::class),
            new LlmRetryExecutor(new NullLogger(), maxRetries: 0, initialDelayMs: 1),
        );
    }

    public function testEvaluateGoalWithGenericEvaluationSuccess(): void
    {
        $goal = $this->createGoal(1, 'Test Goal');
        $history = $this->createHistory(10, '{"result":"some output"}');

        $this->evaluationRepo
            ->expects(self::once())
            ->method('save')
            ->willReturnCallback(function (GoalEvaluation $eval, bool $flush): void {
                self::assertTrue($eval->isSuccess());
                self::assertSame(1.0, $eval->getScore());
                self::assertTrue($flush);
            });

        $this->goalRepo
            ->expects(self::once())
            ->method('save');

        $evaluation = $this->service->evaluateGoal($goal, $history);

        self::assertTrue($evaluation->isSuccess());
        self::assertSame(1.0, $evaluation->getScore());
        self::assertSame('system', $evaluation->getEvaluatedBy());
        self::assertSame(1, $evaluation->getGoalId());
        self::assertSame(10, $evaluation->getAgentHistoryId());
    }

    public function testEvaluateGoalWithGenericEvaluationFailure(): void
    {
        $goal = $this->createGoal(2, 'Test Goal');
        $history = $this->createHistory(11, '{"result":""}');

        $evaluation = $this->service->evaluateGoal($goal, $history);

        self::assertFalse($evaluation->isSuccess());
        self::assertSame(0.0, $evaluation->getScore());
        self::assertStringContainsString('lieferte kein Ergebnis', $evaluation->getFeedback());
    }

    public function testEvaluateGoalWithNullDetails(): void
    {
        $goal = $this->createGoal(6, 'Test Goal');
        $history = $this->createHistory(15, null);

        $evaluation = $this->service->evaluateGoal($goal, $history);

        self::assertFalse($evaluation->isSuccess());
        self::assertSame(0.0, $evaluation->getScore());
    }

    public function testEvaluateGoalUpdatesGoalWithEvaluation(): void
    {
        $goal = $this->createGoal(7, 'Test Goal');
        $history = $this->createHistory(16, '{"result":"output"}');

        $this->goalRepo
            ->expects(self::once())
            ->method('save')
            ->willReturnCallback(function (AgentGoal $g, bool $flush): void {
                self::assertNotNull($g->getLastEvaluation());
                self::assertNotNull($g->getLastEvaluationScore());
                self::assertTrue($flush);
            });

        $this->service->evaluateGoal($goal, $history);
    }

    public function testEvaluateMultipleGoals(): void
    {
        $goal1 = $this->createGoal(1, 'Goal 1');
        $goal2 = $this->createGoal(2, 'Goal 2');
        $history1 = $this->createHistory(10, '{"result":"a"}');
        $history2 = $this->createHistory(11, '{"result":"b"}');

        $this->evaluationRepo->expects(self::exactly(2))->method('save');
        $this->goalRepo->expects(self::exactly(2))->method('save');

        $evaluations = $this->service->evaluateMultipleGoals([
            ['goal' => $goal1, 'history' => $history1],
            ['goal' => $goal2, 'history' => $history2],
        ]);

        self::assertCount(2, $evaluations);
    }

    public function testEvaluateMultipleGoalsSkipsInvalidPairs(): void
    {
        $goal = $this->createGoal(1, 'Goal');
        $history = $this->createHistory(10, '{"result":"a"}');

        $this->evaluationRepo->expects(self::once())->method('save');

        $evaluations = $this->service->evaluateMultipleGoals([
            ['goal' => $goal, 'history' => $history],
            ['goal' => 'invalid', 'history' => 'invalid'],
            ['invalid' => 'pair'],
        ]);

        self::assertCount(1, $evaluations);
    }

    public function testGetLastEvaluationForGoalReturnsFirst(): void
    {
        $eval = new GoalEvaluation();
        $this->evaluationRepo
            ->method('findByGoal')
            ->with(42)
            ->willReturn([$eval]);

        self::assertSame($eval, $this->service->getLastEvaluationForGoal(42));
    }

    public function testGetLastEvaluationForGoalReturnsNullWhenEmpty(): void
    {
        $this->evaluationRepo
            ->method('findByGoal')
            ->willReturn([]);

        self::assertNull($this->service->getLastEvaluationForGoal(42));
    }

    public function testGetAverageScoreForGoal(): void
    {
        $this->evaluationRepo
            ->method('getAverageScoreForGoal')
            ->with(42)
            ->willReturn(0.75);

        self::assertSame(0.75, $this->service->getAverageScoreForGoal(42));
    }

    public function testGetSuccessRateForGoal(): void
    {
        $this->evaluationRepo
            ->method('getSuccessRateForGoal')
            ->with(42)
            ->willReturn(80.0);

        self::assertSame(80.0, $this->service->getSuccessRateForGoal(42));
    }

    private function createGoal(int $id, string $title): AgentGoal
    {
        $goal = new AgentGoal();
        $goal->setUserIdentifier('tenant1')
            ->setTitle($title)
            ->setStatus('active');

        $reflection = new \ReflectionClass(AgentGoal::class);
        $prop = $reflection->getProperty('id');
        $prop->setValue($goal, $id);

        return $goal;
    }

    private function createHistory(int $id, ?string $details): AgentHistory
    {
        $user = new UserProfile();
        $history = new AgentHistory();
        $history->setAction('test')
            ->setDetails($details)
            ->setUser($user);

        $reflection = new \ReflectionClass(AgentHistory::class);
        $prop = $reflection->getProperty('id');
        $prop->setValue($history, $id);

        return $history;
    }
}
