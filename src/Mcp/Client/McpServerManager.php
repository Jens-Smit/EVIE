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
        private readonly LoggerInterface $mcpLogger,
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
            $this->mcpLogger->info(sprintf('Verbunden mit MCP-Server "%s".', $serverAlias));
        } catch (\Throwable $e) {
            $this->mcpLogger->error(sprintf('Fehler beim Verbinden mit MCP-Server "%s": %s', $serverAlias, $e->getMessage()));
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

        $this->mcpLogger->debug(sprintf('Tools für MCP-Server "%s" abgerufen.', $serverAlias));
        return $result;
    }

    public function callTool(string $serverAlias, string $toolName, array $arguments): mixed
    {
        $client = $this->getClient($serverAlias);
        $this->mcpLogger->info(sprintf('Aufruf von Tool "%s" auf Server "%s" mit Argumenten: %s', $toolName, $serverAlias, json_encode($arguments)));

        try {
            $result = $client->callTool($toolName, $arguments);
            $this->mcpLogger->info(sprintf('Ergebnis von Tool "%s" auf Server "%s": %s', $toolName, $serverAlias, json_encode($result)));
            return $result;
        } catch (\Throwable $e) {
            $this->mcpLogger->error(sprintf('Fehler beim Aufruf von Tool "%s" auf Server "%s": %s', $toolName, $serverAlias, $e->getMessage()));
            throw new McpServerUnavailableException(
                sprintf('Fehler beim Aufruf von Tool "%s" auf Server "%s": %s', $toolName, $serverAlias, $e->getMessage()),
                previous: $e,
            );
        }
    }

    public function disconnectAll(): void
    {
        foreach ($this->clients as $alias => $client) {
            $client->disconnect();
            $this->mcpLogger->info(sprintf('Verbindung zu MCP-Server "%s" getrennt.', $alias));
        }
        $this->clients = [];
    }
}