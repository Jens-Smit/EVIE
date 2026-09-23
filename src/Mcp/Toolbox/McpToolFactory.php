<?php
// src/Mcp/Toolbox/McpToolFactory.php

namespace App\Mcp\Toolbox;

use App\Mcp\Client\McpServerManager;
use App\Mcp\Exception\McpServerUnavailableException;
use Symfony\Contracts\Cache\CacheInterface;

class McpToolFactory
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
     * Gibt alle Tools für alle Server zurück (für die Toolbox): statische
     * YAML-Server PLUS Frontend-freigegebene Server (aktive
     * McpServerDefinition-Eintraege, sichtbar im Manager-Merge).
     *
     * @return iterable<McpRemoteToolMetadata>
     */
    public function getTools(): iterable
    {
        $aliases = array_values(array_unique([
            ...$this->serverAliases,
            ...$this->serverManager->getAvailableServerAliases(),
        ]));

        foreach ($aliases as $alias) {
            try {
                $tools = $this->cache->get(
                    sprintf('mcp_tools_%s', $alias),
                    fn () => $this->serverManager->listToolsFor($alias),
                );
            } catch (McpServerUnavailableException) {
                // Ein nicht erreichbarer Server (insb. dynamisch freigegebene)
                // darf die Tool-Discovery der verbleibenden Server nicht
                // blockieren: überspringen und fortfahren.
                continue;
            }

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
