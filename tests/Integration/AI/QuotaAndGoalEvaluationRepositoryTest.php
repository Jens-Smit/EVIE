<?php

declare(strict_types=1);

namespace App\Tests\Integration\AI;

use App\Entity\AgentGoal;
use App\Entity\GoalEvaluation;
use App\Entity\TenantQuota;
use App\Repository\GoalEvaluationRepository;
use App\Repository\TenantQuotaRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration-Tests fuer TenantQuotaRepository und GoalEvaluationRepository
 * (echte Datenbank, Blueprint \u00a77.2).
 *
 * Beide Repositories waren laut Coverage-Report ungetestet (0%).
 */
class QuotaAndGoalEvaluationRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private TenantQuotaRepository $quotaRepository;
    private GoalEvaluationRepository $evaluationRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->quotaRepository = $this->entityManager->getRepository(TenantQuota::class);
        $this->evaluationRepository = $this->entityManager->getRepository(GoalEvaluation::class);

        $schemaTool = new SchemaTool($this->entityManager);
        try {
            $schemaTool->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());
        } catch (\Throwable) {
        }
    }

    protected function tearDown(): void
    {
        try {
            foreach ([GoalEvaluation::class, AgentGoal::class, TenantQuota::class] as $class) {
                $this->entityManager->createQueryBuilder()
                    ->delete($class, 'e')
                    ->getQuery()->execute();
            }
            $this->entityManager->clear();
        } catch (\Throwable) {
        }
        parent::tearDown();
        static::ensureKernelShutdown();
    }

    private function createGoal(string $userIdentifier): AgentGoal
    {
        $goal = new AgentGoal();
        $goal->setUserIdentifier($userIdentifier);
        $goal->setTitle('Test-Ziel');
        $goal->setStatus('active');
        $this->entityManager->persist($goal);
        $this->entityManager->flush();
        return $goal;
    }

    private function createEvaluation(AgentGoal $goal, bool $success, ?float $score): GoalEvaluation
    {
        $evaluation = new GoalEvaluation();
        $evaluation->setGoal($goal);
        $evaluation->setGoalId($goal->getId());
        $evaluation->setSuccess($success);
        $evaluation->setScore($score);
        $evaluation->setEvaluatedBy('test');
        $this->entityManager->persist($evaluation);
        $this->entityManager->flush();
        return $evaluation;
    }

    public function testFindOrCreateCreatesNewQuota(): void
    {
        $quota = $this->quotaRepository->findOrCreate('quota-create@test.de');

        self::assertSame('quota-create@test.de', $quota->getUserIdentifier());
        self::assertNotNull($quota->getId());
        self::assertSame($quota->getId(), $this->quotaRepository->findOrCreate('quota-create@test.de')->getId());
    }

    public function testRecordTokenUsageAccumulates(): void
    {
        $this->quotaRepository->recordTokenUsage('quota-tokens@test.de', 100);
        $this->quotaRepository->recordTokenUsage('quota-tokens@test.de', 50);

        $quota = $this->quotaRepository->findByUserIdentifier('quota-tokens@test.de');
        self::assertNotNull($quota);
        self::assertSame(150, $quota->getCurrentDayUsage());
    }

    public function testRecordRequestUsageAccumulates(): void
    {
        $this->quotaRepository->recordRequestUsage('quota-requests@test.de');
        $this->quotaRepository->recordRequestUsage('quota-requests@test.de');

        $quota = $this->quotaRepository->findByUserIdentifier('quota-requests@test.de');
        self::assertNotNull($quota);
        self::assertSame(2, $quota->getCurrentHourUsage());
    }

    public function testIsQuotaExceededIsFalseUnderLimit(): void
    {
        $this->quotaRepository->recordTokenUsage('quota-limit@test.de', 10);

        self::assertFalse($this->quotaRepository->isQuotaExceeded('quota-limit@test.de'));
    }

    public function testGetQuotaUsageReturnsStructure(): void
    {
        $usage = $this->quotaRepository->getQuotaUsage('quota-usage@test.de');

        self::assertArrayHasKey('max_tokens_per_day', $usage);
        self::assertArrayHasKey('current_day_usage', $usage);
        self::assertArrayHasKey('remaining_daily_tokens', $usage);
        self::assertArrayHasKey('max_requests_per_hour', $usage);
        self::assertArrayHasKey('max_concurrent_requests', $usage);
    }

    public function testResetAllDailyAndHourlyUsage(): void
    {
        $this->quotaRepository->recordTokenUsage('quota-reset@test.de', 100);
        $this->quotaRepository->recordRequestUsage('quota-reset@test.de');

        $dailyCount = $this->quotaRepository->resetAllDailyUsage();
        $hourlyCount = $this->quotaRepository->resetAllHourlyUsage();

        self::assertGreaterThanOrEqual(1, $dailyCount);
        self::assertGreaterThanOrEqual(1, $hourlyCount);
        $quota = $this->quotaRepository->findByUserIdentifier('quota-reset@test.de');
        self::assertSame(0, $quota->getCurrentDayUsage());
        self::assertSame(0, $quota->getCurrentHourUsage());
    }

    public function testGetAllQuotasReturnsArrayStructure(): void
    {
        $this->quotaRepository->findOrCreate('quota-all@test.de');
        $quotas = $this->quotaRepository->getAllQuotas();

        self::assertNotEmpty($quotas);
        $identifiers = array_column($quotas, 'user_identifier');
        self::assertContains('quota-all@test.de', $identifiers);
    }

    public function testUpdateQuotaSettingsAppliesValues(): void
    {
        $quota = $this->quotaRepository->updateQuotaSettings(
            'quota-settings@test.de',
            5000,
            100,
            2
        );

        self::assertSame(5000, $quota->getMaxTokensPerDay());
        self::assertSame(100, $quota->getMaxRequestsPerHour());
        self::assertSame(2, $quota->getMaxConcurrentRequests());
        self::assertTrue($quota->isCustom());
    }

    public function testSaveAndRemoveQuota(): void
    {
        $quota = new TenantQuota();
        $quota->setUserIdentifier('quota-remove@test.de');
        $this->quotaRepository->save($quota, true);
        $id = $quota->getId();
        self::assertNotNull($id);

        $this->quotaRepository->remove($quota, true);
        self::assertNull($this->quotaRepository->find($id));
    }

    public function testGoalEvaluationSaveFindAndRemove(): void
    {
        $goal = $this->createGoal('goal-eval@test.de');
        $evaluation = $this->createEvaluation($goal, true, 0.9);

        $found = $this->evaluationRepository->findByGoal($goal->getId());
        self::assertCount(1, $found);
        self::assertTrue($found[0]->isSuccess());

        $byUser = $this->evaluationRepository->findByUser('goal-eval@test.de');
        self::assertCount(1, $byUser);

        $this->evaluationRepository->remove($evaluation, true);
        self::assertSame([], $this->evaluationRepository->findByGoal($goal->getId()));
    }

    public function testFindRecentEvaluations(): void
    {
        $goal = $this->createGoal('goal-recent@test.de');
        $this->createEvaluation($goal, true, 0.8);

        $recent = $this->evaluationRepository->findRecentEvaluations(30);
        self::assertNotEmpty($recent);
    }

    public function testAverageScoreAndSuccessRate(): void
    {
        $goal = $this->createGoal('goal-score@test.de');
        $this->createEvaluation($goal, true, 0.8);
        $this->createEvaluation($goal, false, 0.4);

        self::assertEqualsWithDelta(0.6, $this->evaluationRepository->getAverageScoreForGoal($goal->getId()), 0.0001);
        self::assertEqualsWithDelta(50.0, $this->evaluationRepository->getSuccessRateForGoal($goal->getId()), 0.001);
    }

    public function testAverageScoreReturnsNullWithoutScores(): void
    {
        $goal = $this->createGoal('goal-null@test.de');
        $this->createEvaluation($goal, true, null);

        self::assertNull($this->evaluationRepository->getAverageScoreForGoal($goal->getId()));
        self::assertSame(0.0, $this->evaluationRepository->getSuccessRateForGoal(999999));
    }
}
