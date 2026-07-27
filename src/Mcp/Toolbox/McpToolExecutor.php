<?php
// src/Mcp/Toolbox/McpToolExecutor.php

namespace App\Mcp\Toolbox;

use App\Mcp\Client\McpServerManager;
use App\Mcp\Exception\McpToolExecutionFailed;
use Psr\Log\LoggerInterface;

final class McpToolExecutor
{
    public function __construct(
        private readonly McpServerManager $serverManager,
        private readonly LoggerInterface $mcpLogger,
    ) {
    }

    public function execute(string $serverAlias, string $toolName, array $arguments = []): mixed
    {
        $this->mcpLogger->info(sprintf('Führe Tool "%s" auf Server "%s" aus.', $toolName, $serverAlias));
        try {
            return $this->serverManager->callTool($serverAlias, $toolName, $arguments);
        } catch (\Throwable $e) {
            $this->mcpLogger->error(sprintf('Fehler bei der Ausführung von Tool "%s" auf Server "%s": %s', $toolName, $serverAlias, $e->getMessage()));
            throw new McpToolExecutionFailed($serverAlias, $toolName, $e->getMessage());
        }
    }
}