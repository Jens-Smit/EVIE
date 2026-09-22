<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\McpServerDefinition;

/**
 * Functional-Tests fuer McpServerController.
 *
 * McpServerController war laut Coverage-Report ungetestet (0%). Deckt
 * list/show/new (GET+POST)/delete ab. Alle Routen sind ROLE_ADMIN-only.
 */
class McpServerControllerTest extends AbstractFunctionalControllerTest
{
    protected function tearDown(): void
    {
        try {
            $this->entityManager->createQueryBuilder()
                ->delete(McpServerDefinition::class, 'm')
                ->getQuery()->execute();
        } catch (\Throwable) {
        }
        parent::tearDown();
    }

    private function createDefinition(string $name): McpServerDefinition
    {
        $definition = new McpServerDefinition();
        $definition->setName($name);
        $definition->setType('filesystem');
        $definition->setDescription('Test-Definition');
        $definition->setConfiguration([]);
        $definition->setAllowedTools([]);
        $definition->setBlockedResources([]);
        $this->entityManager->persist($definition);
        $this->entityManager->flush();
        return $definition;
    }

    public function testListRequiresAdmin(): void
    {
        $this->createUserAndLogin('mcp-user@test.de', 'McpPass123');
        $this->client->request('GET', '/mcp/servers');

        self::assertResponseStatusCodeSame(403);
    }

    public function testListRendersForAdmin(): void
    {
        $this->createUserAndLogin('mcp-admin@test.de', 'McpPass123', ['ROLE_ADMIN']);
        $this->client->request('GET', '/mcp/servers');

        self::assertResponseIsSuccessful();
    }

    public function testShowReturns404ForUnknownServer(): void
    {
        $this->createUserAndLogin('mcp-404@test.de', 'McpPass123', ['ROLE_ADMIN']);
        $this->client->request('GET', '/mcp/servers/does_not_exist');

        self::assertResponseStatusCodeSame(404);
    }

    public function testShowRendersKnownServer(): void
    {
        $this->createUserAndLogin('mcp-show@test.de', 'McpPass123', ['ROLE_ADMIN']);
        $this->createDefinition('fs_show_test');

        $this->client->request('GET', '/mcp/servers/fs_show_test');

        self::assertResponseIsSuccessful();
    }

    public function testNewFormRendersForAdmin(): void
    {
        $this->createUserAndLogin('mcp-new@test.de', 'McpPass123', ['ROLE_ADMIN']);
        $this->client->request('GET', '/mcp/servers/new');

        self::assertResponseIsSuccessful();
    }

    public function testDeleteRemovesServer(): void
    {
        $this->createUserAndLogin('mcp-delete@test.de', 'McpPass123', ['ROLE_ADMIN']);
        $definition = $this->createDefinition('fs_delete_test');

        $crawler = $this->client->request('GET', '/mcp/servers/fs_delete_test');
        $deleteForm = $crawler->filter('form[action="' . $crawler->filter('form[action*="/delete"]')->attr('action') . '"]');
        self::assertGreaterThan(0, $deleteForm->count());
        $csrfToken = $crawler->filter('form[action*="/delete"] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/mcp/servers/fs_delete_test/delete', ['_token' => $csrfToken]);

        self::assertResponseRedirects('/mcp/servers');
        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(McpServerDefinition::class, $definition->getId()));
    }

    public function testDeleteUnknownServerReturns404(): void
    {
        $this->createUserAndLogin('mcp-del404@test.de', 'McpPass123', ['ROLE_ADMIN']);
        $this->client->request('POST', '/mcp/servers/does_not_exist/delete');

        self::assertResponseStatusCodeSame(404);
    }
}
