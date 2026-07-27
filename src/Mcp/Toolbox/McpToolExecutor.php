<?php
// src/Mcp/Toolbox/McpToolExecutor.php

namespace App\Mcp\Toolbox;

use App\Mcp\Client\McpServerManager;
use App\Mcp\Exception\McpToolExecutionFailed;
use App\Mcp\Exception\McpToolNotFoundException;
use App\Mcp\Exception\McpServerUnavailableException;
use Psr\Log\LoggerInterface;

/**
 * Executes MCP tool calls and handles errors appropriately.
 * This class is used by McpToolFactory to provide the executor for each MCP tool.
 */
final class McpToolExecutor
{
    public function __construct(
        private readonly McpServerManager $serverManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Executes an MCP tool with the given arguments.
     *
     * @param string $serverAlias The alias of the MCP server.
     * @param string $toolName The name of the tool on the MCP server.
     * @param array<string, mixed> $arguments The arguments for the tool.
     * @return mixed The result of the tool execution.
     * @throws McpToolExecutionFailed If the tool execution fails.
     */
    public function execute(string $serverAlias, string $toolName, array $arguments = []): mixed
    {
        try {
            $this->logger->debug('Executing MCP tool {tool} on server {server} with arguments: {args}', [
                'tool' => $toolName,
                'server' => $serverAlias,
                'args' => $arguments,
            ]);

            $result = $this->serverManager->callTool($serverAlias, $toolName, $arguments);

            $this->logger->debug('MCP tool {tool} on server {server} executed successfully', [
                'tool' => $toolName,
                'server' => $serverAlias,
            ]);

            return $result;

        } catch (McpServerUnavailableException $e) {
            $this->logger->error('MCP server {server} unavailable: {error}', [
                'server' => $serverAlias,
                'error' => $e->getMessage(),
            ]);
            throw new McpToolExecutionFailed($serverAlias, $toolName, $e->getMessage(), $e);

        } catch (McpToolNotFoundException $e) {
            $this->logger->error('MCP tool {tool} not found on server {server}: {error}', [
                'tool' => $toolName,
                'server' => $serverAlias,
                'error' => $e->getMessage(),
            ]);
            throw new McpToolExecutionFailed($serverAlias, $toolName, $e->getMessage(), $e);

        } catch (\Throwable $e) {
            $this->logger->error('MCP tool {tool} on server {server} failed: {error}', [
                'tool' => $toolName,
                'server' => $serverAlias,
                'error' => $e->getMessage(),
            ]);
            throw new McpToolExecutionFailed($serverAlias, $toolName, $e->getMessage(), $e);
        }
    }
}