<?php

declare(strict_types=1);

// tests/Unit/Mcp/Toolbox/McpToolExecutorTest.php

namespace App\Tests\Unit\Mcp\Toolbox;

use App\Mcp\Client\McpServerManager;
use App\Mcp\Exception\McpServerUnavailableException;
use App\Mcp\Exception\McpToolExecutionFailed;
use App\Mcp\Toolbox\McpToolExecutor;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class McpToolExecutorTest extends TestCase
{
    private McpServerManager&MockObject $serverManager;

    protected function setUp(): void
    {
        $this->serverManager = $this->createMock(McpServerManager::class);
    }

    private function createExecutor(array $aliases = ['github', 'filesystem']): McpToolExecutor
    {
        return new McpToolExecutor($this->serverManager, $aliases);
    }

    public function testInvokeDelegatesToServerManager(): void
    {
        $this->serverManager
            ->expects(self::once())
            ->method('callTool')
            ->with('github', 'search_repos', ['q' => 'evie'])
            ->willReturn(['result' => 'ok']);

        $executor = $this->createExecutor();
        self::assertSame(['result' => 'ok'], $executor('github', 'search_repos', ['q' => 'evie']));
    }

    public function testInvokeWithoutAliasWhitelistSkipsGuard(): void
    {
        $this->serverManager
            ->method('callTool')
            ->with('weather_server', 'get_weather', [])
            ->willReturn('sunny');

        $executor = new McpToolExecutor($this->serverManager);
        self::assertSame('sunny', $executor('weather_server', 'get_weather'));
    }

    public function testInvokeAllowsFrontendApprovedServerAlias(): void
    {
        // Frontend-Freigabe: Der Alias ist nicht in der statischen Liste,
        // aber im Manager-Merge (aktive McpServerDefinition) enthalten.
        $this->serverManager
            ->method('hasServer')
            ->willReturnCallback(static fn (string $alias): bool => $alias === 'website_researcher');
        $this->serverManager
            ->expects(self::once())
            ->method('callTool')
            ->with('website_researcher', 'fetch_imprint', ['url' => 'https://example.com'])
            ->willReturn(['firma' => 'Example GmbH']);

        $executor = $this->createExecutor(['github', 'filesystem']);
        self::assertSame(
            ['firma' => 'Example GmbH'],
            $executor('website_researcher', 'fetch_imprint', ['url' => 'https://example.com'])
        );
    }

    public function testInvokeRejectsUnknownServerAlias(): void
    {
        $this->serverManager->expects(self::never())->method('callTool');
        $this->serverManager->method('hasServer')->willReturn(false);
        $this->serverManager->method('getAvailableServerAliases')->willReturn([]);

        $executor = $this->createExecutor();
        try {
            $executor('weather_server', 'get_weather');
            self::fail('McpToolExecutionFailed expected');
        } catch (McpToolExecutionFailed $e) {
            self::assertStringContainsString('Unbekannter MCP-Server "weather_server"', $e->getMessage());
            self::assertStringContainsString('github, filesystem', $e->getMessage());
        }
    }

    public function testInvokeWrapsServerUnavailableException(): void
    {
        $this->serverManager
            ->method('callTool')
            ->willThrowException(new McpServerUnavailableException('Server down'));

        $executor = $this->createExecutor();
        try {
            $executor('github', 'search_repos');
            self::fail('McpToolExecutionFailed expected');
        } catch (McpToolExecutionFailed $e) {
            self::assertStringContainsString('Server down', $e->getMessage());
            self::assertStringContainsString('github', $e->getToolCallResult());
        }
    }

    public function testInvokeWrapsGenericThrowable(): void
    {
        $this->serverManager
            ->method('callTool')
            ->willThrowException(new \LogicException('boom'));

        $executor = $this->createExecutor();
        try {
            $executor('filesystem', 'read_file', ['path' => '/tmp/x']);
            self::fail('McpToolExecutionFailed expected');
        } catch (McpToolExecutionFailed $e) {
            self::assertStringContainsString('boom', $e->getMessage());
            self::assertStringContainsString('read_file', $e->getToolCallResult());
        }
    }
}
