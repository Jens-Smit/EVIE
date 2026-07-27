<?php
// src/Mcp/Exception/McpToolNotFoundException.php

namespace App\Mcp\Exception;

/**
 * Exception thrown when a requested MCP tool is not found.
 */
final class McpToolNotFoundException extends \RuntimeException
{
    /**
     * Creates an exception for a missing tool.
     *
     * @param string $toolName The name of the missing tool.
     * @param string|null $serverAlias The alias of the MCP server (optional).
     */
    public static function forTool(string $toolName, string $serverAlias = null): self
    {
        $message = sprintf('MCP tool "%s" not found', $toolName);
        if ($serverAlias) {
            $message .= sprintf(' on server "%s"', $serverAlias);
        }
        return new self($message);
    }
}