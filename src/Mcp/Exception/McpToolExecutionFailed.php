<?php
// src/Mcp/Exception/McpToolExecutionFailed.php

namespace App\Mcp\Exception;

use Symfony\AI\Agent\Toolbox\Exception\ToolExecutionExceptionInterface;

final class McpToolExecutionFailed extends \RuntimeException implements ToolExecutionExceptionInterface
{
    public function __construct(
        private readonly string $serverAlias,
        private readonly string $toolName,
        string $reason,
    ) {
        parent::__construct($reason);
    }

    public function getToolCallResult(): string
    {
        return sprintf('MCP-Tool "%s" auf Server "%s" ist fehlgeschlagen.', $this->toolName, $this->serverAlias);
    }
}