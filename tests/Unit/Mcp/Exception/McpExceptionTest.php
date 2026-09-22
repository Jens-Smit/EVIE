<?php

declare(strict_types=1);

// tests/Unit/Mcp/Exception/McpExceptionTest.php

namespace App\Tests\Unit\Mcp\Exception;

use App\Mcp\Exception\McpServerUnavailableException;
use App\Mcp\Exception\McpToolExecutionFailed;
use App\Mcp\Exception\McpToolNotFoundException;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Toolbox\Exception\ToolExecutionExceptionInterface;

final class McpExceptionTest extends TestCase
{
    public function testUnknownServerContainsAlias(): void
    {
        $e = McpServerUnavailableException::unknownServer('weather_server');
        self::assertSame('Unbekannter MCP-Server "weather_server".', $e->getMessage());
    }

    public function testConnectionFailedContainsReasonAndPrevious(): void
    {
        $previous = new \RuntimeException('timeout');
        $e = McpServerUnavailableException::connectionFailed('github', 'timeout', $previous);
        self::assertSame('MCP-Server "github" nicht erreichbar: timeout', $e->getMessage());
        self::assertSame($previous, $e->getPrevious());
    }

    public function testToolExecutionFailedCarriesServerAndTool(): void
    {
        $e = new McpToolExecutionFailed('github', 'search_repos', 'Fehler');
        self::assertSame('Fehler', $e->getMessage());
        self::assertSame(
            'MCP-Tool "search_repos" auf Server "github" ist fehlgeschlagen: Fehler',
            $e->getToolCallResult()
        );
    }

    public function testToolExecutionFailedImplementsToolExecutionExceptionInterface(): void
    {
        $e = new McpToolExecutionFailed('github', 'search_repos', 'Fehler');
        self::assertInstanceOf(ToolExecutionExceptionInterface::class, $e);
    }

    public function testToolNotFoundWithoutServer(): void
    {
        $e = McpToolNotFoundException::forTool('read_file');
        self::assertSame('MCP tool "read_file" not found', $e->getMessage());
    }

    public function testToolNotFoundWithServer(): void
    {
        $e = McpToolNotFoundException::forTool('read_file', 'filesystem');
        self::assertSame('MCP tool "read_file" not found on server "filesystem"', $e->getMessage());
    }
}
