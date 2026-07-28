<?php
// src/Mcp/Exception/McpServerUnavailableException.php

namespace App\Mcp\Exception;

final class McpServerUnavailableException extends \RuntimeException
{
    public static function unknownServer(string $serverAlias): self
    {
        return new self(sprintf('Unbekannter MCP-Server "%s".', $serverAlias));
    }

    public static function connectionFailed(string $serverAlias, string $reason, \Throwable $previous = null): self
    {
        return new self(
            sprintf('MCP-Server "%s" nicht erreichbar: %s', $serverAlias, $reason),
            0,
            $previous
        );
    }
}