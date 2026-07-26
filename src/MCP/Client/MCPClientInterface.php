<?php
// src/MCP/Client/MCPClientInterface.php
namespace App\MCP\Client;

use App\MCP\Exception\MCPException;

/**
 * Interface for MCP clients.
 */
interface MCPClientInterface
{
    /**
     * Initializes the MCP client and connects to the server.
     *
     * @throws MCPException If initialization fails.
     */
    public function initialize(): void;

    /**
     * Lists all available tools provided by the MCP server.
     *
     * @return array<array{name: string, description: string, inputSchema: array}>
     * @throws MCPException If listing tools fails.
     */
    public function listTools(): array;

    /**
     * Calls a specific tool with the given arguments.
     *
     * @param string $toolName The name of the tool to call.
     * @param array<string, mixed> $arguments The arguments for the tool.
     * @return mixed The result of the tool call.
     * @throws MCPException If the tool call fails.
     */
    public function callTool(string $toolName, array $arguments = []): mixed;
}
