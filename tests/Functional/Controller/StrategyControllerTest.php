<?php

declare(strict_types=1);

// tests/Functional/Controller/StrategyControllerTest.php

namespace App\Tests\Functional\Controller;

use App\Entity\AgentGoal;
use App\Entity\GoalEvaluation;
use App\Repository\GoalEvaluationRepository;

/**
 * Functional-Tests fuer den StrategyController (Strategie-Review):
 *
 *  - GET  /strategy                      -> Review-Seite (Login-Pflicht)
 *  - POST /strategy/evaluate             -> Analyse anstossen
 *  - POST /strategy/suggestion/{id}/apply -> Vorschlag anwenden
 *  - GET  /strategy/evaluation/{id}     -> Detail (404, Fremd-Zugriff 403)
 */
class StrategyControllerTest extends AbstractFunctionalControllerTestCase
{
    protected function tearDown(): void
    {
        try {
            $this->entityManager->createQueryBuilder()
                ->delete(GoalEvaluation::class, 'e')
                ->getQuery()->execute();
            $this->entityManager->createQueryBuilder()
                ->delete(AgentGoal::class, 'g')
                ->getQuery()->execute();
        } catch (\Throwable) {
        }
        parent::tearDown();
    }

    private function createGoal(string $userIdentifier, string $status = 'active'): AgentGoal
    {
        $goal = new AgentGoal();
        $goal->setUserIdentifier($userIdentifier);
        $goal->setTitle('Ziel');
        $goal->setStatus($status);
        $this->entityManager->persist($goal);
        $this->entityManager->flush();
        return $goal;
    }

    private function createEvaluation(AgentGoal $goal, bool $success): GoalEvaluation
    {
        $evaluation = new GoalEvaluation();
        $evaluation->setGoal($goal);
        $evaluation->setSuccess($success);
        $evaluation->setCreatedAt(new \DateTimeImmutable());
        $this->entityManager->persist($evaluation);
        $this->entityManager->flush();
        return $evaluation;
    }

    public function testIndexRedirectsAnonymousUserToLogin(): void
    {
        $this->client->request('GET', '/strategy');
        $this->assertResponseRedirects('/login');
    }

    public function testIndexRendersWithoutGoals(): void
    {
        $this->createUserAndLogin('strategy-index@test.de', 'StrategyPass123');
        $this->client->request('GET', '/strategy');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'Keine Ziele für die Analyse vorhanden.',
            $this->client->getResponse()->getContent()
        );
    }

    public function testIndexRendersEvaluationsForUser(): void
    {
        $user = $this->createUserAndLogin('strategy-eval@test.de', 'StrategyPass123');
        $goal = $this->createGoal($user->getUserIdentifier());
        $evaluation = $this->createEvaluation($goal, true);

        $this->client->request('GET', '/strategy');
        self::assertResponseIsSuccessful();

        /** @var GoalEvaluationRepository $evaluationRepo */
        $evaluationRepo = static::getContainer()->get(GoalEvaluationRepository::class);
        $evaluations = $evaluationRepo->findByUser($user->getUserIdentifier());
        self::assertCount(1, $evaluations);
        self::assertSame($evaluation->getId(), $evaluations[0]->getId());
    }

    public function testEvaluateRedirectsToIndexWithFlash(): void
    {
        $this->createUserAndLogin('strategy-evaluate@test.de', 'StrategyPass123');
        $this->client->request('POST', '/strategy/evaluate');
        $this->assertResponseRedirects('/strategy');
    }

    public function testEvaluateRedirectsAnonymousUserToLogin(): void
    {
        $this->client->request('POST', '/strategy/evaluate');
        $this->assertResponseRedirects('/login');
    }

    public function testApplySuggestionWithUnknownIdShowsErrorFlash(): void
    {
        $this->createUserAndLogin('strategy-apply-unknown@test.de', 'StrategyPass123');
        $this->client->request('POST', '/strategy/suggestion/999/apply');
        $this->assertResponseRedirects('/strategy');
        $this->client->followRedirect();
        self::assertStringContainsString(
            'Vorschlag nicht gefunden',
            $this->client->getResponse()->getContent()
        );
    }

    public function testEvaluationDetailRedirectsAnonymousUserToLogin(): void
    {
        $this->client->request('GET', '/strategy/evaluation/1');
        $this->assertResponseRedirects('/login');
    }

    public function testEvaluationDetailReturns404ForUnknownEvaluation(): void
    {
        $this->createUserAndLogin('strategy-detail-404@test.de', 'StrategyPass123');
        $this->client->request('GET', '/strategy/evaluation/999999');
        self::assertResponseStatusCodeSame(404);
    }

    public function testEvaluationDetailRendersForOwnEvaluation(): void
    {
        $user = $this->createUserAndLogin('strategy-detail-own@test.de', 'StrategyPass123');
        $goal = $this->createGoal($user->getUserIdentifier());
        $evaluation = $this->createEvaluation($goal, true);

        $this->client->request('GET', '/strategy/evaluation/' . $evaluation->getId());
        self::assertResponseIsSuccessful();
    }

    public function testEvaluationDetailDeniesForeignEvaluation(): void
    {
        $owner = $this->createUser('strategy-detail-owner@test.de', 'StrategyPass123');
        $goal = $this->createGoal($owner->getUserIdentifier());
        $evaluation = $this->createEvaluation($goal, true);

        $this->createUserAndLogin('strategy-detail-intruder@test.de', 'StrategyPass123');
        $this->client->request('GET', '/strategy/evaluation/' . $evaluation->getId());
        self::assertResponseStatusCodeSame(403);
    }
}
