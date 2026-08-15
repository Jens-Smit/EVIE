<?php

declare(strict_types=1);

// src/Mcp/Client/McpServerManager.php

namespace App\Mcp\Client;

use App\Mcp\Exception\McpServerUnavailableException;
use Mcp\Client;
use Mcp\Client\Transport\HttpTransport;
use Mcp\Client\Transport\StdioTransport;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class McpServerManager
{
    private const MAX_RETRIES = 2;
    private const RETRY_DELAY_SECONDS = 1;

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
        private readonly LoggerInterface $logger = new NullLogger(),
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

        $lastException = null;
        for ($attempt = 1; $attempt <= self::MAX_RETRIES; ++$attempt) {
            try {
                $client->connect($transport);
                $this->logger->info('MCP-Server verbunden', [
                    'server' => $serverAlias,
                    'attempt' => $attempt,
                ]);

                return $this->clients[$serverAlias] = $client;
            } catch (\Throwable $e) {
                $lastException = $e;
                $this->logger->warning('MCP-Server Verbindungsversuch fehlgeschlagen', [
                    'server' => $serverAlias,
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);

                if ($attempt < self::MAX_RETRIES) {
                    usleep(self::RETRY_DELAY_SECONDS * 1_000_000);
                }
            }
        }

        throw new McpServerUnavailableException(
            sprintf('MCP-Server "%s" nach %d Versuchen nicht erreichbar: %s', $serverAlias, self::MAX_RETRIES, $lastException instanceof \Throwable ? $lastException->getMessage() : 'unbekannt'),
            previous: $lastException,
        );
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
            $result = $client->callTool($toolName, $arguments);
            $this->logger->info('MCP-Tool ausgefuehrt', [
                'server' => $serverAlias,
                'tool' => $toolName,
            ]);

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('MCP-Tool-Aufruf fehlgeschlagen', [
                'server' => $serverAlias,
                'tool' => $toolName,
                'error' => $e->getMessage(),
            ]);

            throw new McpServerUnavailableException(
                sprintf('Fehler beim Aufruf von Tool "%s" auf Server "%s": %s', $toolName, $serverAlias, $e->getMessage()),
                previous: $e,
            );
        }
    }

    public function disconnectAll(): void
    {
        foreach ($this->clients as $alias => $client) {
            try {
                $client->disconnect();
            } catch (\Throwable $e) {
                $this->logger->warning('Fehler beim Trennen der MCP-Server-Verbindung', [
                    'server' => $alias,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        $this->clients = [];
    }

    /**
     * Prueft, ob ein Server-Alias in der Konfiguration existiert (Whitelist).
     */
    public function hasServer(string $serverAlias): bool
    {
        return isset($this->serverConfigs[$serverAlias]);
    }

    /**
     * @return list<string>
     */
    public function getAvailableServerAliases(): array
    {
        return array_keys($this->serverConfigs);
    }
}
