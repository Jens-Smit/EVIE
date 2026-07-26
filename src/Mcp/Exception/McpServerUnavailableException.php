<?php

namespace App\Mcp\Exception;

/**
 * Exception thrown when an MCP server is unavailable or fails to connect.
 */
final class McpServerUnavailableException extends \RuntimeException
{
    public static function connectionFailed(string $serverAlias, string $reason, \Throwable $previous = null): self
    {
        return new self(
            sprintf('MCP server "%s" connection failed: %s', $serverAlias, $reason),
            0,
            $previous
        );
    }

    public static function unknownServer(string $serverAlias): self
    {
        return new self(sprintf('Unknown MCP server: "%s"', $serverAlias));
    }
}
