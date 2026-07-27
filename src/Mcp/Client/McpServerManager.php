<?php
// src/Mcp/Client/McpServerManager.php

namespace App\Mcp\Client;

use Mcp\Client;
use Mcp\Client\Transport\StdioTransport;
use Mcp\Client\Transport\HttpTransport;
use App\Mcp\Exception\McpServerUnavailableException;
use Psr\Log\LoggerInterface;

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

    public function getClient(string $serverAlias): Client
    {
        if (isset($this->clients[$serverAlias])) {
            return $this->clients[$serverAlias];
        }

        $config = $this->serverConfigs[$serverAlias] ?? null;
        if (!$config) {
            throw new McpServerUnavailableException(sprintf('Unbekannter MCP-Server "%s".', $serverAlias));
        }

        $client = Client::builder()
            ->setClientInfo('evie', '1.0.0')
            ->setInitTimeout(30)
            ->setRequestTimeout(120)
            ->build();

        $transport = match ($config['transport']) {
            'stdio' => new StdioTransport(
                command: $config['command'],
                args: $config['arguments'] ?? [],
            ),
            'http' => new HttpTransport($config['url']),
        };

        try {
            $client->connect($transport);
            $this->logger->info('Connected to MCP server: {server}', ['server' => $serverAlias]);
        } catch (\Throwable $e) {
            throw new McpServerUnavailableException(
                sprintf('MCP-Server "%s" nicht erreichbar: %s', $serverAlias, $e->getMessage()),
                previous: $e,
            );
        }

        return $this->clients[$serverAlias] = $client;
    }

    /** @return array<string, array{name: string, description: string, inputSchema: array}> */
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
                sprintf('Tool "%s" not found on server "%s".', $toolName, $serverAlias)
            );
        }
    }

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
}