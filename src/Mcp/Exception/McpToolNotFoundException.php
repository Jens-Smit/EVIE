<?php
// src/Mcp/Exception/McpToolNotFoundException.php

namespace App\Mcp\Exception;

final class McpToolNotFoundException extends \RuntimeException
{
    public static function forTool(string $toolName, string $serverAlias = null): self
    {
        $message = sprintf('MCP tool "%s" not found', $toolName);
        if ($serverAlias) {
            $message .= sprintf(' on server "%s"', $serverAlias);
        }
        return new self($message);
    }
}