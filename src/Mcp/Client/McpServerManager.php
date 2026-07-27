<?php
// src/Mcp/Client/McpServerManager.php

namespace App\Mcp\Client;

use Mcp\Client;
use Mcp\Client\Transport\StdioTransport;
use Mcp\Client\Transport\HttpTransport;
use App\Mcp\Exception\McpServerUnavailableException;

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
    ) {
    }

    public function getClient(string $serverAlias): Client
    {
        if (isset($this->clients[$serverAlias])) {
            return $this->clients[$serverAlias];
        }

        $config = $this->serverConfigs[$serverAlias] ?? throw new McpServerUnavailableException(
            sprintf('Unbekannter MCP-Server "%s".', $serverAlias)
        );

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
        } catch (\Throwable $e) {
            throw new McpServerUnavailableException(
                sprintf('MCP-Server "%s" nicht erreichbar: %s', $serverAlias, $e->getMessage()),
                previous: $e,
            );
        }

        return $this->clients[$serverAlias] = $client;
    }

    /**
     * @return array<string, array{name: string, description: string, inputSchema: array}>
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

        return $result;
    }

    public function callTool(string $serverAlias, string $toolName, array $arguments): mixed
    {
        $client = $this->getClient($serverAlias);

        try {
            return $client->callTool($toolName, $arguments);
        } catch (\Throwable $e) {
            throw new McpServerUnavailableException(
                sprintf('Fehler beim Aufruf von Tool "%s" auf Server "%s": %s', $toolName, $serverAlias, $e->getMessage()),
                previous: $e,
            );
        }
    }

    public function disconnectAll(): void
    {
        foreach ($this->clients as $client) {
            $client->disconnect();
        }
        $this->clients = [];
    }
}