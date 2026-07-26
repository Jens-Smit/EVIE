<?php
// src/MCP/Registry/ToolRegistry.php
namespace App\MCP\Registry;

use App\MCP\Exception\MCPException;
use App\MCP\Server\ServerManager;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * Registry for MCP tools with caching.
 */
final class ToolRegistry
{
    private const CACHE_KEY = 'evie_mcp_tools';

    /** @var array<array{name: string, description: string, server: string, inputSchema: array}>|null */
    private ?array $tools = null;

    public function __construct(
        private ServerManager $serverManager,
        private CacheItemPoolInterface $cache,
        private LoggerInterface $logger,
        private int $cacheTtl = 300,
    ) {
    }

    /**
     * Lists all available tools.
     *
     * @return array<array{name: string, description: string, server: string, inputSchema: array}>
     */
    public function listTools(): array
    {
        if ($this->tools === null) {
            $cacheItem = $this->cache->getItem(self::CACHE_KEY);
            if ($cacheItem->isHit()) {
                $this->tools = $cacheItem->get();
                $this->logger->debug('Loaded tools from cache.');
            } else {
                $this->tools = $this->serverManager->listTools();
                $cacheItem->set($this->tools);
                $cacheItem->expiresAfter($this->cacheTtl);
                $this->cache->save($cacheItem);
                $this->logger->debug('Cached tools for {ttl} seconds.', ['ttl' => $this->cacheTtl]);
            }
        }

        return $this->tools;
    }

    /**
     * Calls a tool by name.
     *
     * @param string $toolName The name of the tool.
     * @param array<string, mixed> $arguments The arguments for the tool.
     * @return mixed The result of the tool call.
     * @throws MCPException If the tool is not found or the call fails.
     */
    public function callTool(string $toolName, array $arguments = []): mixed
    {
        $tools = $this->listTools();
        $tool = null;

        foreach ($tools as $t) {
            if ($t['name'] === $toolName) {
                $tool = $t;
                break;
            }
        }

        if (!$tool) {
            throw MCPException::toolNotFound($toolName);
        }

        $this->logger->debug('Calling tool {tool} on server {server}', [
            'tool' => $toolName,
            'server' => $tool['server'],
        ]);

        return $this->serverManager->callTool($tool['server'], $toolName, $arguments);
    }

    /**
     * Clears the tool cache.
     */
    public function clearCache(): void
    {
        $this->tools = null;
        $this->cache->delete(self::CACHE_KEY);
        $this->logger->debug('Cleared tool cache.');
    }
}
