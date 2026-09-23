<?php

declare(strict_types=1);

// tests/Unit/Mcp/Client/McpServerManagerTest.php

namespace App\Tests\Unit\Mcp\Client;

use App\Mcp\Client\McpServerManager;
use App\Mcp\Exception\McpServerUnavailableException;
use PHPUnit\Framework\TestCase;

final class McpServerManagerTest extends TestCase
{
    public function testHasServerReflectsConfiguration(): void
    {
        $manager = new McpServerManager([
            'github' => ['transport' => 'http', 'url' => 'http://localhost:8080'],
        ]);

        self::assertTrue($manager->hasServer('github'));
        self::assertFalse($manager->hasServer('weather_server'));
    }

    public function testGetAvailableServerAliasesReturnsConfiguredAliases(): void
    {
        $manager = new McpServerManager([
            'github' => ['transport' => 'http', 'url' => 'http://localhost:8080'],
            'filesystem' => ['transport' => 'stdio', 'command' => 'npx'],
        ]);

        self::assertSame(['github', 'filesystem'], $manager->getAvailableServerAliases());
    }

    public function testDynamicConfigLoaderMergesFrontendApprovedServers(): void
    {
        $manager = new McpServerManager(
            ['github' => ['transport' => 'http', 'url' => 'http://github-mcp:8080']],
        );
        $manager->setDynamicConfigLoader(static fn (): array => [
            'website_researcher' => ['transport' => 'http', 'url' => 'http://research-mcp:8080'],
        ]);

        self::assertTrue($manager->hasServer('website_researcher'));
        self::assertTrue($manager->hasServer('github'));
        self::assertSame(
            ['github', 'website_researcher'],
            $manager->getAvailableServerAliases()
        );
    }

    public function testDynamicConfigLoaderFailureKeepsStaticServers(): void
    {
        $manager = new McpServerManager([
            'github' => ['transport' => 'http', 'url' => 'http://github-mcp:8080'],
        ]);
        $manager->setDynamicConfigLoader(static function (): array {
            throw new \RuntimeException('DB nicht verfuegbar');
        });

        self::assertTrue($manager->hasServer('github'));
        self::assertFalse($manager->hasServer('website_researcher'));
    }

    public function testWithoutLoaderOnlyStaticServers(): void
    {
        $manager = new McpServerManager([
            'github' => ['transport' => 'http', 'url' => 'http://github-mcp:8080'],
        ]);

        self::assertSame(['github'], $manager->getAvailableServerAliases());
    }

    public function testDynamicConfigLoaderViaProviderInstance(): void
    {
        $repository = $this->createMock(\App\Repository\McpServerDefinitionRepository::class);
        $definition = new \App\Entity\McpServerDefinition();
        $definition->setName('research_mcp');
        $definition->setType('custom');
        $definition->setDescription('Frontend-freigegebener Server');
        $definition->setConfiguration(['url' => 'http://research-mcp:8080']);
        $repository->method('findAllActive')->willReturn([$definition]);

        $manager = new McpServerManager([]);
        $manager->setDynamicConfigLoader(new \App\Mcp\Client\McpDynamicServerConfigProvider($repository));

        self::assertTrue($manager->hasServer('research_mcp'));
        self::assertSame(['research_mcp'], $manager->getAvailableServerAliases());
    }

    public function testGetClientThrowsForUnknownAlias(): void
    {
        $manager = new McpServerManager([]);

        try {
            $manager->getClient('weather_server');
            self::fail('McpServerUnavailableException expected');
        } catch (McpServerUnavailableException $e) {
            self::assertSame('Unbekannter MCP-Server "weather_server".', $e->getMessage());
        }
    }

    public function testGetClientThrowsForUnknownTransport(): void
    {
        $manager = new McpServerManager([
            'broken' => ['transport' => 'grpc'],
        ]);

        $this->expectException(\Throwable::class);
        $manager->getClient('broken');
    }

    public function testDisconnectAllClearsClients(): void
    {
        $manager = new McpServerManager([]);
        $manager->disconnectAll();
        self::assertFalse($manager->hasServer('any'));
    }
}
