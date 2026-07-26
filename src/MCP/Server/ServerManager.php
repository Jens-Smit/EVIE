<?php
// src/MCP/Server/ServerManager.php
namespace App\MCP\Server;

use App\MCP\Client\JsonRpcClient;
use App\MCP\Client\MCPClientInterface;
use App\MCP\Exception\MCPException;
use App\MCP\Model\ServerConfig;
use App\MCP\Transport\HttpTransport;
use App\MCP\Transport\StdioTransport;
use App\MCP\Transport\TransportInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Manages MCP server connections and tool discovery.
 */
final class ServerManager
{
    /** @var array<string, MCPClientInterface> */
    private array $clients = [];

    /** @var array<string, array> */
    private array $toolsCache = [];

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private int $cacheTtl = 300,
    ) {
    }

    /**
     * Adds a server configuration and initializes its client.
     */
    public function addServer(ServerConfig $config): void
    {
        $this->clients[$config->name] = $this->createClient($config);
        $this->logger->info('MCP server added: {server}', ['server' => $config->name]);
    }

    /**
     * Lists all tools from all registered servers.
     *
     * @return array<array{name: string, description: string, server: string, inputSchema: array}>
     */
    public function listTools(): array
    {
        $tools = [];

        foreach ($this->clients as $serverName => $client) {
            try {
                if (!isset($this->toolsCache[$serverName]) || $this->isCacheStale($serverName)) {
                    $this->toolsCache[$serverName] = $client->listTools();
                    $this->logger->debug('Cached tools for server: {server}', ['server' => $serverName]);
                }

                foreach ($this->toolsCache[$serverName] as $tool) {
                    $tools[] = [
                        'name' => $tool['name'],
                        'description' => $tool['description'] ?? '',
                        'server' => $serverName,
                        'inputSchema' => $tool['inputSchema'] ?? [],
                    ];
                }
            } catch (MCPException $e) {
                $this->logger->error('Failed to list tools for server {server}: {error}', [
                    'server' => $serverName,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $tools;
    }

    /**
     * Calls a tool on the specified server.
     *
     * @param string $serverName The name of the server.
     * @param string $toolName The name of the tool.
     * @param array<string, mixed> $arguments The arguments for the tool.
     * @return mixed The result of the tool call.
     * @throws MCPException If the tool call fails.
     */
    public function callTool(string $serverName, string $toolName, array $arguments = []): mixed
    {
        if (!isset($this->clients[$serverName])) {
            throw MCPException::server(sprintf('Server "%s" not found.', $serverName));
        }

        try {
            $this->logger->debug('Calling tool {tool} on server {server}', [
                'tool' => $toolName,
                'server' => $serverName,
            ]);

            return $this->clients[$serverName]->callTool($toolName, $arguments);
        } catch (MCPException $e) {
            $this->logger->error('Failed to call tool {tool} on server {server}: {error}', [
                'tool' => $toolName,
                'server' => $serverName,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Creates an MCP client for the given server configuration.
     */
    private function createClient(ServerConfig $config): MCPClientInterface
    {
        $transport = $this->createTransport($config);
        return new JsonRpcClient($transport);
    }

    /**
     * Creates a transport for the given server configuration.
     */
    private function createTransport(ServerConfig $config): TransportInterface
    {
        return match ($config->transport) {
            'http' => new HttpTransport($this->httpClient, $config->url ?? '', $config->timeout),
            'stdio' => new StdioTransport($config->command ?? '', $config->arguments, $config->timeout),
            default => throw MCPException::protocol(sprintf('Unsupported transport: %s', $config->transport)),
        };
    }

    /**
     * Checks if the cache for a server is stale.
     */
    private function isCacheStale(string $serverName): bool
    {
        // In a real implementation, you would track the last cache time.
        // For simplicity, we always return true here.
        return true;
    }
}
