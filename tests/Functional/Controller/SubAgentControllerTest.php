<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\SubAgent;
use App\Entity\UserProfile;

/**
 * Functional-Tests fuer SubAgentController.
 *
 * SubAgentController war laut Coverage-Report ungetestet (0%). Deckt
 * list/get/history/create/delete ab, inkl. 400 bei unvollstaendigen
 * Create-Payloads.
 */
class SubAgentControllerTest extends AbstractFunctionalControllerTest
{
    protected function tearDown(): void
    {
        try {
            $this->entityManager->createQueryBuilder()
                ->delete(SubAgent::class, 's')
                ->getQuery()->execute();
        } catch (\Throwable) {
        }
        parent::tearDown();
    }

    private function createProfile(string $identifier): UserProfile
    {
        $profile = new UserProfile();
        $profile->setUserIdentifier($identifier);
        $profile->setName($identifier);
        $this->entityManager->persist($profile);
        $this->entityManager->flush();
        return $profile;
    }

    private function createSubAgent(UserProfile $profile, string $name = 'sub_agent_test'): SubAgent
    {
        $subAgent = new SubAgent();
        $subAgent->setName($name);
        $subAgent->setDescription('Test SubAgent');
        $subAgent->setUser($profile);
        $subAgent->setStatus('active');
        $this->entityManager->persist($subAgent);
        $this->entityManager->flush();
        return $subAgent;
    }

    public function testListReturnsOwnSubAgents(): void
    {
        $user = $this->createUserAndLogin('subagent-list@test.de', 'SubAgentPass123');
        $profile = $this->createProfile($user->getUserIdentifier());
        $this->createSubAgent($profile);

        $this->client->request('GET', '/api/subagents');

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertGreaterThanOrEqual(1, count($data));
        self::assertSame('sub_agent_test', $data[0]['name']);
    }

    public function testGetReturnsSubAgent(): void
    {
        $user = $this->createUserAndLogin('subagent-get@test.de', 'SubAgentPass123');
        $profile = $this->createProfile($user->getUserIdentifier());
        $subAgent = $this->createSubAgent($profile);

        $this->client->request('GET', '/api/subagents/' . $subAgent->getId());

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame($subAgent->getId(), $data['id']);
        self::assertSame([], $data['history']);
    }

    public function testGetReturns404ForUnknownSubAgent(): void
    {
        $this->createUserAndLogin('subagent-404@test.de', 'SubAgentPass123');
        $this->client->request('GET', '/api/subagents/999999');

        self::assertResponseStatusCodeSame(404);
    }

    public function testHistoryReturnsEmptyArray(): void
    {
        $user = $this->createUserAndLogin('subagent-hist@test.de', 'SubAgentPass123');
        $profile = $this->createProfile($user->getUserIdentifier());
        $subAgent = $this->createSubAgent($profile);

        $this->client->request('GET', '/api/subagents/' . $subAgent->getId() . '/history');

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame([], $data);
    }

    public function testCreateReturns400WithoutName(): void
    {
        $this->createUserAndLogin('subagent-c400@test.de', 'SubAgentPass123');
        $this->client->request('POST', '/api/subagents', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['description' => 'no name']));

        self::assertResponseStatusCodeSame(400);
    }

    public function testCreatePersistsSubAgent(): void
    {
        $user = $this->createUserAndLogin('subagent-create@test.de', 'SubAgentPass123');
        $this->client->request('POST', '/api/subagents', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'name' => 'created_agent',
            'description' => 'Created via API',
            'capabilities' => ['research'],
        ]));

        self::assertResponseStatusCodeSame(201);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertTrue($data['success']);
        self::assertSame('created_agent', $data['subAgent']['name']);
    }

    public function testDeleteRemovesSubAgent(): void
    {
        $user = $this->createUserAndLogin('subagent-delete@test.de', 'SubAgentPass123');
        $profile = $this->createProfile($user->getUserIdentifier());
        $subAgent = $this->createSubAgent($profile);

        $this->client->request('DELETE', '/api/subagents/' . $subAgent->getId());

        self::assertResponseIsSuccessful();
        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(SubAgent::class, $subAgent->getId()));
    }
}
