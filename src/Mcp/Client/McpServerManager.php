<?php
// src/Mcp/Client/McpServerManager.php

namespace App\Mcp\Client;

use Mcp\Client;
use Mcp\Client\Transport\StdioTransport;
use Mcp\Client\Transport\HttpTransport;
use App\Mcp\Exception\McpServerUnavailableException;
use Psr\Log\LoggerInterface;

/**
 * Manages MCP server connections and provides tool discovery/calling capabilities.
 */
final class McpServerManager
{
    /** @var array<string, Client> */
    private array $clients = [];

    /**
     * @param array<string, array{
     *     transport: 'stdio'|'http',
     *     command?: string,
     *     arguments?: string[],
     *     url?: string
     * }> $serverConfigs
     */
    public function __construct(
        private readonly array $serverConfigs,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Gets or creates a client for the given server alias.
     *
     * @throws McpServerUnavailableException If the server is unknown or fails to connect.
     */
    public function getClient(string $serverAlias): Client
    {
        if (isset($this->clients[$serverAlias])) {
            return $this->clients[$serverAlias];
        }

        $config = $this->serverConfigs[$serverAlias] ?? null;
        if (!$config) {
            throw McpServerUnavailableException::unknownServer($serverAlias);
        }

        $client = Client::builder()
            ->setClientInfo('evie', '1.0.0')
            ->setInitTimeout(30)
            ->setRequestTimeout(120)
            ->build();

        $transport = $this->createTransport($config);

        try {
            $client->connect($transport);
            $this->logger->info('Connected to MCP server: {server}', ['server' => $serverAlias]);
        } catch (\Throwable $e) {
            throw McpServerUnavailableException::connectionFailed(
                $serverAlias,
                $e->getMessage(),
                $e
            );
        }

        return $this->clients[$serverAlias] = $client;
    }

    /**
     * Lists all tools available on the specified server.
     *
     * @param string $serverAlias The server alias.
     * @return array<string, array{name: string, description: string, inputSchema: array}>
     * @throws McpServerUnavailableException If the server is unavailable.
     */
    public function listToolsFor(string $serverAlias): array
    {
        $client = $this->getClient($serverAlias);
        $tools = $client->listTools();

        $result = [];
        foreach ($tools as $tool) {
            $result[$tool->name] = [
                'name' => $tool->name,
                'description' => $tool->description,
                'inputSchema' => $tool->inputSchema,
            ];
        }

        $this->logger->debug('Listed {count} tools for server {server}', [
            'count' => count($result),
            'server' => $serverAlias,
        ]);

        return $result;
    }

    /**
     * Calls a tool on the specified server.
     *
     * @param string $serverAlias The server alias.
     * @param string $toolName The name of the tool to call.
     * @param array<string, mixed> $arguments The arguments for the tool.
     * @return mixed The result of the tool call.
     * @throws McpServerUnavailableException If the server is unavailable.
     * @throws \App\Mcp\Exception\McpToolNotFoundException If the tool is not found.
     */
    public function callTool(string $serverAlias, string $toolName, array $arguments = []): mixed
    {
        $client = $this->getClient($serverAlias);

        $this->logger->debug('Calling tool {tool} on server {server} with arguments: {args}', [
            'tool' => $toolName,
            'server' => $serverAlias,
            'args' => $arguments,
        ]);

        try {
            return $client->callTool($toolName, $arguments);
        } catch (\InvalidArgumentException $e) {
            throw new \App\Mcp\Exception\McpToolNotFoundException(
                $toolName,
                $serverAlias
            );
        }
    }

    /**
     * Disconnects all clients.
     */
    public function disconnectAll(): void
    {
        foreach ($this->clients as $serverAlias => $client) {
            try {
                $client->disconnect();
                $this->logger->info('Disconnected from MCP server: {server}', ['server' => $serverAlias]);
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to disconnect from MCP server {server}: {error}', [
                    'server' => $serverAlias,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        $this->clients = [];
    }

    /**
     * Creates a transport based on the server configuration.
     *
     * @throws McpServerUnavailableException If the transport type is unsupported.
     */
    private function createTransport(array $config): StdioTransport|HttpTransport
    {
        return match ($config['transport']) {
            'stdio' => new StdioTransport(
                command: $config['command'] ?? '',
                args: $config['arguments'] ?? [],
            ),
            'http' => new HttpTransport($config['url'] ?? ''),
            default => throw McpServerUnavailableException::connectionFailed(
                (string) $config['transport'],
                sprintf('Unsupported transport type: %s', $config['transport'])
            ),
        };
    }
}