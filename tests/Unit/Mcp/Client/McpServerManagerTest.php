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
