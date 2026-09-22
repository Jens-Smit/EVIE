<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Document;
use App\Entity\ToolDefinition;

/**
 * Functional-Tests fuer DashboardController.
 *
 * DashboardController war laut Coverage-Report ungetestet (0%). Deckt
 * den Index-Endpunkt ab: Auth, leere Datenbasis und bevoelkerte Daten.
 */
class DashboardControllerTest extends AbstractFunctionalControllerTest
{
    protected function tearDown(): void
    {
        try {
            $this->entityManager->createQueryBuilder()
                ->delete(ToolDefinition::class, 't')
                ->getQuery()->execute();
            $this->entityManager->createQueryBuilder()
                ->delete(Document::class, 'd')
                ->getQuery()->execute();
        } catch (\Throwable) {
        }
        parent::tearDown();
    }

    public function testDashboardRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/dashboard');

        self::assertResponseRedirects('/login');
    }

    public function testDashboardReturnsEmptyData(): void
    {
        $this->createUserAndLogin('dash-empty@test.de', 'DashPass123');
        $this->client->request('GET', '/api/dashboard');

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame([], $data['recentActions']);
        self::assertSame([], $data['pendingTools']);
        self::assertSame([], $data['recentDocuments']);
        self::assertSame([], $data['subAgents']);
    }

    public function testDashboardReturnsPendingTools(): void
    {
        $this->createUserAndLogin('dash-tools@test.de', 'DashPass123');

        $tool = new ToolDefinition();
        $tool->setName('dash_tool');
        $tool->setDescription('Test-Tool');
        $tool->setStatus('pending');
        $this->entityManager->persist($tool);
        $this->entityManager->flush();

        $this->client->request('GET', '/api/dashboard');

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertCount(1, $data['pendingTools']);
        self::assertSame('dash_tool', $data['pendingTools'][0]['name']);
    }
}
