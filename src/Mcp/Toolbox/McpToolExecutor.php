<?php
// src/Mcp/Toolbox/McpToolExecutor.php

namespace App\Mcp\Toolbox;

use App\Mcp\Client\McpServerManager;
use App\Mcp\Exception\McpToolExecutionFailed;

final class McpToolExecutor
{
    public function __construct(
        private readonly McpServerManager $serverManager,
    ) {
    }

    public function execute(string $serverAlias, string $toolName, array $arguments = []): mixed
    {
        try {
            return $this->serverManager->callTool($serverAlias, $toolName, $arguments);
        } catch (\Throwable $e) {
            throw new McpToolExecutionFailed($serverAlias, $toolName, $e->getMessage());
        }
    }
}
