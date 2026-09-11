<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mcp\Toolbox;

use App\AI\Skills\Tool\ToolInterface;
use App\Mcp\Toolbox\McpRemoteToolMetadata;
use App\Mcp\Toolbox\McpToolExecutor;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Tool\ExecutionReference;

final class McpRemoteToolMetadataTest extends TestCase
{
    public function testImplementsToolInterface(): void
    {
        $tool = new McpRemoteToolMetadata(
            'mcp_search',
            'Searches via MCP',
            ['type' => 'object'],
            'github',
            'search_repos'
        );

        self::assertInstanceOf(ToolInterface::class, $tool);
    }

    public function testGetters(): void
    {
        $tool = new McpRemoteToolMetadata(
            'mcp_search',
            'Searches via MCP',
            ['type' => 'object', 'properties' => ['q' => ['type' => 'string']]],
            'github',
            'search_repos'
        );

        self::assertSame('mcp_search', $tool->getName());
        self::assertSame('Searches via MCP', $tool->getDescription());
        self::assertSame(['type' => 'object', 'properties' => ['q' => ['type' => 'string']]], $tool->getInputSchema());
        self::assertSame('github', $tool->getServerAlias());
        self::assertSame('search_repos', $tool->getRemoteName());
    }

    public function testInvokeReturnsMetadataWithParameters(): void
    {
        $tool = new McpRemoteToolMetadata('mcp_search', 'd', [], 'github', 'search_repos');

        $result = $tool->__invoke(['q' => 'evie', 'limit' => 5]);

        self::assertSame('github', $result['server']);
        self::assertSame('search_repos', $result['tool']);
        self::assertSame(['q' => 'evie', 'limit' => 5], $result['parameters']);
    }

    public function testInvokeWithEmptyParameters(): void
    {
        $tool = new McpRemoteToolMetadata('mcp_noop', 'd', [], 'playwright', 'screenshot');

        $result = $tool->__invoke();

        self::assertSame('playwright', $result['server']);
        self::assertSame('screenshot', $result['tool']);
        self::assertSame([], $result['parameters']);
    }

    public function testGetExecutionReferencePointsToMcpToolExecutor(): void
    {
        $tool = new McpRemoteToolMetadata('mcp_search', 'd', [], 'github', 'search_repos');

        $ref = $tool->getExecutionReference();

        self::assertInstanceOf(ExecutionReference::class, $ref);
        self::assertSame(McpToolExecutor::class, $ref->getClass());
        self::assertSame('execute', $ref->getMethod());
    }
}
