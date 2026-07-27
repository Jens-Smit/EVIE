<?php
// src/Mcp/Exception/McpToolExecutionFailed.php

namespace App\Mcp\Exception;

use Symfony\AI\Agent\Toolbox\Exception\ToolExecutionExceptionInterface;

final class McpToolExecutionFailed extends \RuntimeException implements ToolExecutionExceptionInterface
{
    public function __construct(
        private string $serverAlias,
        private string $toolName,
        string $message,
        \Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getToolCallResult(): string
    {
        return sprintf(
            'MCP-Tool "%s" auf Server "%s" ist fehlgeschlagen: %s',
            $this->toolName,
            $this->serverAlias,
            $this->getMessage()
        );
    }
}