<?php
// src/Mcp/Toolbox/McpToolFactory.php

namespace App\Mcp\Toolbox;

use App\Mcp\Client\McpServerManager;
use Symfony\Contracts\Cache\CacheInterface;

final class McpToolFactory
{
    /** @param string[] $serverAliases */
    public function __construct(
        private readonly McpServerManager $serverManager,
        private readonly CacheInterface $cache,
        private readonly array $serverAliases,
        private readonly int $cacheTtl = 300,
    ) {
    }

    /**
     * Gibt alle Tools für alle Server zurück (für die Toolbox).
     * @return iterable<McpRemoteToolMetadata>
     */
    public function getTools(): iterable
    {
        foreach ($this->serverAliases as $alias) {
            $tools = $this->cache->get(
                sprintf('mcp_tools_%s', $alias),
                fn () => $this->serverManager->listToolsFor($alias),
            );

            foreach ($tools as $toolName => $tool) {
                yield new McpRemoteToolMetadata(
                    name: sprintf('%s_%s', $alias, $toolName),
                    description: $tool['description'],
                    inputSchema: $tool['inputSchema'],
                    serverAlias: $alias,
                    remoteName: $toolName,
                );
            }
        }
    }
}
