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

        // P1-4: Timeout/Retry/Auth sind pro Server konfigurierbar (mit
        // sinnvollen Defaults). So lassen sich langsame externe Server
        // oder Server mit Auth-Requirement gezielt behandeln.
        $initTimeout = $config['init_timeout'] ?? 30;
        $requestTimeout = $config['request_timeout'] ?? 120;
        $maxRetries = $config['max_retries'] ?? self::MAX_RETRIES;
        $retryDelay = $config['retry_delay_seconds'] ?? self::RETRY_DELAY_SECONDS;

        $client = Client::builder()
            ->setClientInfo('evie', '1.0.0')
            ->setInitTimeout($initTimeout)
            ->setRequestTimeout($requestTimeout)
            ->build();

        $transport = match ($config['transport']) {
            'stdio' => new StdioTransport(
                command: $config['command'],
                args: $config['arguments'] ?? [],
            ),
            // P1-4: fuer MCP-Server mit Auth (z. B. GitHub MCP) wird
            // ein Bearer-Token als Authorization-Header gesetzt, statt
            // eine offene Verbindung ohne Authentifizierung zuzulassen.
            'http' => $this->buildHttpTransport($config),
        };

        $lastException = null;
        for ($attempt = 1; $attempt <= $maxRetries; ++$attempt) {
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

                if ($attempt < $maxRetries) {
                    usleep($retryDelay * 1_000_000);
                }
            }
        }

        throw new McpServerUnavailableException(
            sprintf('MCP-Server "%s" nach %d Versuchen nicht erreichbar: %s', $serverAlias, $maxRetries, $lastException instanceof \Throwable ? $lastException->getMessage() : 'unbekannt'),
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

    /**
     * Baut den HTTP-Transport fuer einen MCP-Server, optional mit
     * Bearer-Token-Authentifizierung (P1-4).
     *
     * Ist ein auth_token konfiguriert, wird der Authorization-Header
     * gesetzt. Server ohne Token laufen offen (z. B. interne
     * Services), Server mit Token erfordern Auth (z. B. GitHub MCP).
     */
    private function buildHttpTransport(array $config)
    {
        // P1-4: HttpTransport akzeptiert optionale Header fuer Auth.
        $headers = [];
        if (isset($config['auth_token']) && '' !== $config['auth_token']) {
            $headers['Authorization'] = 'Bearer ' . $config['auth_token'];
        }

        return new HttpTransport($config['url'], $headers);
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
