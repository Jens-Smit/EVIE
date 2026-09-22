<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\AgentGoal;
use App\Repository\AgentGoalRepository;

/**
 * Functional-Tests fuer AgentGoalController (Frontend).
 *
 * AgentGoalController war laut Coverage-Report ungetestet (0%). Deckt
 * list/create (Validierung)/activate/pause/approve/delete ab inkl.
 * Tenant-Isolation.
 */
class AgentGoalControllerTest extends AbstractFunctionalControllerTest
{
    private AgentGoalRepository $goalRepository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->goalRepository = static::getContainer()->get(AgentGoalRepository::class);
    }

    protected function tearDown(): void
    {
        try {
            $this->entityManager->createQueryBuilder()
                ->delete(AgentGoal::class, 'g')
                ->getQuery()->execute();
        } catch (\Throwable) {
        }
        parent::tearDown();
    }

    private function createGoal(string $userIdentifier, string $title = 'Ziel'): AgentGoal
    {
        $goal = new AgentGoal();
        $goal->setUserIdentifier($userIdentifier);
        $goal->setTitle($title);
        $goal->setStatus('paused');
        $this->entityManager->persist($goal);
        $this->entityManager->flush();
        return $goal;
    }

    public function testGoalsPageRequiresAuthentication(): void
    {
        $this->client->request('GET', '/agent/goals');

        self::assertResponseRedirects('/login');
    }

    public function testGoalsPageRendersForUser(): void
    {
        $this->createUserAndLogin('goals-page@test.de', 'GoalPass123');
        $this->client->request('GET', '/agent/goals');

        self::assertResponseIsSuccessful();
    }

    public function testCreateGoalWithEmptyTitleShowsError(): void
    {
        $this->createUserAndLogin('goals-empty@test.de', 'GoalPass123');
        $this->client->request('POST', '/agent/goals', ['title' => '']);

        self::assertResponseRedirects('/agent/goals');
    }

    public function testCreateGoalWithInvalidCronShowsError(): void
    {
        $this->createUserAndLogin('goals-cron@test.de', 'GoalPass123');
        $this->client->request('POST', '/agent/goals', [
            'title' => 'Ziel',
            'cron_expression' => 'not-a-cron',
        ]);

        self::assertResponseRedirects('/agent/goals');
        $this->client->followRedirect();
        self::assertSelectorTextContains('', 'Ungültige Cron-Expression');
    }

    public function testCreateGoalPersistsPausedGoal(): void
    {
        $user = $this->createUserAndLogin('goals-create@test.de', 'GoalPass123');
        $this->client->request('POST', '/agent/goals', [
            'title' => 'Woechentliche Zusammenfassung',
            'description' => 'Erstelle jede Woche eine Zusammenfassung',
            'cron_expression' => '0 9 * * 1',
        ]);

        self::assertResponseRedirects('/agent/goals');
        $goals = $this->goalRepository->findByUser($user->getUserIdentifier());
        self::assertCount(1, $goals);
        self::assertSame('Woechentliche Zusammenfassung', $goals[0]->getTitle());
        self::assertSame('paused', $goals[0]->getStatus());
        self::assertTrue($goals[0]->isRequiresApproval());
    }

    public function testActivateUnapprovedGoalShowsWarning(): void
    {
        $user = $this->createUserAndLogin('goals-unapproved@test.de', 'GoalPass123');
        $goal = $this->createGoal($user->getUserIdentifier());

        $this->client->request('POST', '/agent/goals/' . $goal->getId() . '/activate');

        self::assertResponseRedirects('/agent/goals');
        $this->entityManager->clear();
        $refreshed = $this->goalRepository->find($goal->getId());
        self::assertSame('paused', $refreshed->getStatus());
    }

    public function testApproveThenActivateGoal(): void
    {
        $user = $this->createUserAndLogin('goals-approve@test.de', 'GoalPass123');
        $goal = $this->createGoal($user->getUserIdentifier());

        $this->client->request('POST', '/agent/goals/' . $goal->getId() . '/approve');
        self::assertResponseRedirects('/agent/goals');

        $this->client->request('POST', '/agent/goals/' . $goal->getId() . '/activate');
        self::assertResponseRedirects('/agent/goals');

        $this->entityManager->clear();
        $refreshed = $this->goalRepository->find($goal->getId());
        self::assertSame('active', $refreshed->getStatus());
        self::assertTrue($refreshed->isApproved());
    }

    public function testPauseGoal(): void
    {
        $user = $this->createUserAndLogin('goals-pause@test.de', 'GoalPass123');
        $goal = $this->createGoal($user->getUserIdentifier());
        $this->goalRepository->approve($goal);
        $this->goalRepository->activate($goal);

        $this->client->request('POST', '/agent/goals/' . $goal->getId() . '/pause');

        self::assertResponseRedirects('/agent/goals');
        $this->entityManager->clear();
        $refreshed = $this->goalRepository->find($goal->getId());
        self::assertSame('paused', $refreshed->getStatus());
    }

    public function testActivateForeignGoalIsDenied(): void
    {
        $owner = $this->createUser('goals-owner@test.de', 'GoalPass123');
        $goal = $this->createGoal($owner->getUserIdentifier());
        $this->createUserAndLogin('goals-attacker@test.de', 'GoalPass123');

        $this->client->request('POST', '/agent/goals/' . $goal->getId() . '/activate');

        self::assertResponseStatusCodeSame(403);
    }

    public function testDeleteGoal(): void
    {
        $user = $this->createUserAndLogin('goals-delete@test.de', 'GoalPass123');
        $goal = $this->createGoal($user->getUserIdentifier());

        $this->client->request('POST', '/agent/goals/' . $goal->getId());

        self::assertResponseRedirects('/agent/goals');
        self::assertNull($this->goalRepository->find($goal->getId()));
    }

    public function testActivateUnknownGoalShowsError(): void
    {
        $this->createUserAndLogin('goals-unknown@test.de', 'GoalPass123');
        $this->client->request('POST', '/agent/goals/999999/activate');

        self::assertResponseRedirects('/agent/goals');
    }
}
