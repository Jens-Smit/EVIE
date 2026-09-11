<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Strategy;

use App\AI\Agent\LlmRetryExecutor;
use App\AI\Agent\OrchestratorDialogService;
use App\AI\Agent\SubAgentFactory;
use App\AI\Decision\DecisionManager;
use App\AI\Response\FaultTolerantValidator;
use App\AI\Response\JsonResponseEnforcer;
use App\AI\Response\ResponseNormalizer;
use App\AI\Skills\ToolDefinitionGenerator;
use App\AI\Strategy\StrategyManager;
use App\Entity\AgentGoal;
use App\Entity\DecisionLog;
use App\Entity\GoalEvaluation;
use App\Repository\AgentGoalRepository;
use App\Repository\DecisionLogRepository;
use App\Repository\GoalEvaluationRepository;
use App\Repository\ToolDefinitionRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Unit-Tests für StrategyManager (Strategie-Anpassungsvorschläge und HITL-Entscheidungen).
 */
final class StrategyManagerTest extends TestCase
{
    private AgentGoalRepository&MockObject $goalRepo;
    private GoalEvaluationRepository&MockObject $evaluationRepo;
    private DecisionManager&MockObject $decisionManager;
    private OrchestratorDialogService $orchestrator;
    private StrategyManager $manager;

    protected function setUp(): void
    {
        $this->goalRepo = $this->createMock(AgentGoalRepository::class);
        $this->evaluationRepo = $this->createMock(GoalEvaluationRepository::class);
        $this->decisionManager = $this->createMock(DecisionManager::class);
        $this->orchestrator = $this->buildOrchestrator();
        $this->manager = new StrategyManager(
            $this->goalRepo,
            $this->evaluationRepo,
            $this->decisionManager,
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
            $this->createMock(UrlGeneratorInterface::class),
            $this->createMock(JsonResponseEnforcer::class),
            $this->createMock(FaultTolerantValidator::class),
            $this->createMock(ResponseNormalizer::class),
            $this->createMock(ToolDefinitionRepository::class),
            new LlmRetryExecutor(new NullLogger(), maxRetries: 0, initialDelayMs: 1),
        );
    }

    public function testAnalyzeAndSuggestAdjustmentsWithNoGoals(): void
    {
        $this->goalRepo
            ->method('findByUser')
            ->with('tenant1')
            ->willReturn([]);

        $result = $this->manager->analyzeAndSuggestAdjustments('tenant1');

        self::assertSame([], $result['suggestions']);
        self::assertSame('Keine Ziele für die Analyse vorhanden.', $result['summary']);
    }

    public function testAnalyzeAndSuggestAdjustmentsWithGoalsNoEvaluations(): void
    {
        $goal = $this->createGoal(1, 'Test Goal', 'active');

        $this->goalRepo->method('findByUser')->willReturn([$goal]);
        $this->evaluationRepo->method('createQueryBuilder')->willReturn($this->createQueryBuilderMock([]));
        $this->evaluationRepo->method('getAverageScoreForGoal')->willReturn(null);
        $this->evaluationRepo->method('getSuccessRateForGoal')->willReturn(0.0);

        $result = $this->manager->analyzeAndSuggestAdjustments('tenant1');

        self::assertStringContainsString('Analyse von 1 Zielen', $result['summary']);
        self::assertStringContainsString('Erfolgsquote: 0.0%', $result['summary']);
    }

    public function testGenerateSummaryWithSuggestions(): void
    {
        $goal = $this->createGoal(1, 'Test Goal', 'active');

        $this->goalRepo->method('findByUser')->willReturn([$goal]);
        $this->evaluationRepo->method('createQueryBuilder')->willReturn($this->createQueryBuilderMock([]));
        $this->evaluationRepo->method('getAverageScoreForGoal')->willReturn(null);
        $this->evaluationRepo->method('getSuccessRateForGoal')->willReturn(30.0);

        $result = $this->manager->analyzeAndSuggestAdjustments('tenant1');

        self::assertNotEmpty($result['suggestions']);
        self::assertSame('pause_goal', $result['suggestions'][0]['type']);
        self::assertSame('high', $result['suggestions'][0]['priority']);
        self::assertStringContainsString('Anpassungsvorschläge generiert', $result['summary']);
    }

    public function testCreateStrategyAdjustmentDecision(): void
    {
        $decisionLog = new DecisionLog();

        $this->decisionManager
            ->expects(self::once())
            ->method('createDecision')
            ->with('tenant1', 'strategy_adjustment', 'title', 'description', self::anything())
            ->willReturn($decisionLog);

        $result = $this->manager->createStrategyAdjustmentDecision(
            'tenant1',
            'pause_goal',
            'title',
            'description',
            ['context_key' => 'value']
        );

        self::assertSame($decisionLog, $result);
    }

    public function testGetAllSuggestionsForUser(): void
    {
        $goal = $this->createGoal(1, 'Test Goal', 'active');

        $this->goalRepo->method('findByUser')->willReturn([$goal]);
        $this->evaluationRepo->method('createQueryBuilder')->willReturn($this->createQueryBuilderMock([]));
        $this->evaluationRepo->method('getAverageScoreForGoal')->willReturn(null);
        $this->evaluationRepo->method('getSuccessRateForGoal')->willReturn(30.0);

        $suggestions = $this->manager->getAllSuggestionsForUser('tenant1');

        self::assertIsArray($suggestions);
    }

    public function testApplyAdjustmentReturnsFalseForUnknownGoal(): void
    {
        $this->goalRepo
            ->method('find')
            ->with(999)
            ->willReturn(null);

        self::assertFalse($this->manager->applyAdjustment(999, 'pause_goal', 'tenant1'));
    }

    public function testApplyAdjustmentReturnsFalseForWrongTenant(): void
    {
        $goal = $this->createGoal(1, 'Test Goal', 'active');
        $goal->setUserIdentifier('tenant1');

        $this->goalRepo->method('find')->willReturn($goal);

        self::assertFalse($this->manager->applyAdjustment(1, 'pause_goal', 'wrong-tenant'));
    }

    public function testApplyAdjustmentPauseGoal(): void
    {
        $goal = $this->createGoal(1, 'Test Goal', 'active');
        $goal->setUserIdentifier('tenant1');

        $this->goalRepo->method('find')->willReturn($goal);
        $this->goalRepo
            ->expects(self::once())
            ->method('save')
            ->willReturnCallback(function (AgentGoal $g, bool $flush): void {
                self::assertSame('paused', $g->getStatus());
                self::assertTrue($flush);
            });

        self::assertTrue($this->manager->applyAdjustment(1, 'pause_goal', 'tenant1'));
        self::assertSame('paused', $goal->getStatus());
    }

    public function testApplyAdjustmentIncreaseFrequency(): void
    {
        $goal = $this->createGoal(1, 'Test Goal', 'active');
        $goal->setUserIdentifier('tenant1');
        $goal->setCronExpression('0 8 * * *');

        $this->goalRepo->method('find')->willReturn($goal);
        $this->goalRepo->expects(self::once())->method('save');

        self::assertTrue($this->manager->applyAdjustment(1, 'increase_frequency', 'tenant1'));
        self::assertSame('0 * * * *', $goal->getCronExpression());
    }

    public function testApplyAdjustmentReturnsFalseForUnknownType(): void
    {
        $goal = $this->createGoal(1, 'Test Goal', 'active');
        $goal->setUserIdentifier('tenant1');

        $this->goalRepo->method('find')->willReturn($goal);

        self::assertFalse($this->manager->applyAdjustment(1, 'unknown_type', 'tenant1'));
    }

    public function testApplyAdjustmentIncreaseFrequencyWithoutCron(): void
    {
        $goal = $this->createGoal(1, 'Test Goal', 'active');
        $goal->setUserIdentifier('tenant1');

        $this->goalRepo->method('find')->willReturn($goal);
        $this->goalRepo->expects(self::once())->method('save');

        self::assertTrue($this->manager->applyAdjustment(1, 'increase_frequency', 'tenant1'));
        self::assertNull($goal->getCronExpression());
    }

    private function createGoal(int $id, string $title, string $status): AgentGoal
    {
        $goal = new AgentGoal();
        $goal->setUserIdentifier('tenant1')
            ->setTitle($title)
            ->setStatus($status);

        $reflection = new \ReflectionClass(AgentGoal::class);
        $prop = $reflection->getProperty('id');
        $prop->setValue($goal, $id);

        return $goal;
    }

    public function testAnalyzeAndSuggestAdjustmentsIncreaseFrequencySuggestion(): void
    {
        $goal = $this->createGoal(1, 'Top Goal', 'active');
        $evaluation = new GoalEvaluation();
        $reflection = new \ReflectionClass(GoalEvaluation::class);
        $prop = $reflection->getProperty('createdAt');
        $prop->setValue($evaluation, new \DateTimeImmutable());
        $reflection->getMethod('setSuccess')->invoke($evaluation, true);

        $this->goalRepo->method('findByUser')->willReturn([$goal]);
        $this->evaluationRepo->method('createQueryBuilder')->willReturn($this->createQueryBuilderMock(array_fill(0, 9, $evaluation)));
        $this->evaluationRepo->method('getAverageScoreForGoal')->willReturn(0.95);
        $this->evaluationRepo->method('getSuccessRateForGoal')->willReturn(95.0);

        $result = $this->manager->analyzeAndSuggestAdjustments('tenant1');

        $types = array_column($result['suggestions'], 'type');
        self::assertContains('increase_frequency', $types);
        self::assertContains('new_goal_suggestion', $types);
    }

    public function testAnalyzeAndSuggestAdjustmentsStrategyReviewWhenManyExecutions(): void
    {
        $goal = $this->createGoal(1, 'Busy Goal', 'active');
        $evaluation = new GoalEvaluation();
        $reflection = new \ReflectionClass(GoalEvaluation::class);
        $prop = $reflection->getProperty('createdAt');
        $prop->setValue($evaluation, new \DateTimeImmutable());
        $reflection->getMethod('setSuccess')->invoke($evaluation, true);

        $this->goalRepo->method('findByUser')->willReturn([$goal]);
        $this->evaluationRepo->method('createQueryBuilder')->willReturn($this->createQueryBuilderMock(array_fill(0, 10, $evaluation)));
        $this->evaluationRepo->method('getAverageScoreForGoal')->willReturn(0.9);
        $this->evaluationRepo->method('getSuccessRateForGoal')->willReturn(100.0);

        $result = $this->manager->analyzeAndSuggestAdjustments('tenant1');

        $types = array_column($result['suggestions'], 'type');
        self::assertContains('strategy_review', $types);
    }

    private function createQueryBuilderMock(array $result): object
    {
        $qb = $this->createMock(\Doctrine\ORM\QueryBuilder::class);
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $query = $this->createMock(\Doctrine\ORM\Query::class);
        $query->method('getResult')->willReturn($result);
        $qb->method('getQuery')->willReturn($query);
        return $qb;
    }
}
