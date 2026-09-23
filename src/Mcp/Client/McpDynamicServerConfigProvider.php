<?php

declare(strict_types=1);

namespace App\Mcp\Client;

use App\Entity\McpServerDefinition;
use App\Repository\McpServerDefinitionRepository;

/**
 * Uebersetzt die im Frontend freigegebenen MCP-Server-Definitionen
 * (aktive McpServerDefinition-Eintraege, verwaltet ueber /mcp/servers)
 * in Server-Konfigurationen fuer den McpServerManager.
 *
 * Damit durchdringt die Frontend-Freigabe (Anlegen/Aktivieren eines
 * MCP-Servers im Admin-UI) die Laufzeit-Alias-Whitelist des
 * McpToolExecutor: Ein freigegebener Server ist ohne erneute YAML- oder
 * Code-Aenderung als Alias nutzbar (Blueprint §5.D - Freigabe im Frontend).
 *
 * Nur Eintraege mit transportfaehiger Konfiguration (url fuer http bzw.
 * command fuer stdio) werden geliefert; unvollstaendige Definitionen
 * werden uebersprungen, damit der Manager keine unbenutzbaren Aliasse
 * anbietet.
 */
final class McpDynamicServerConfigProvider
{
    public function __construct(
        private readonly McpServerDefinitionRepository $repository,
    ) {
    }

    /**
     * @return array<string, array{transport: 'stdio'|'http', command?: string, arguments?: string[], url?: string, auth_token?: string}>
     */
    public function getServerConfigs(): array
    {
        $configs = [];
        foreach ($this->repository->findAllActive() as $definition) {
            $config = $this->toServerConfig($definition);
            if ($config !== null) {
                $configs[$definition->getName()] = $config;
            }
        }

        return $configs;
    }

    /**
     * @return array{transport: 'stdio'|'http', command?: string, arguments?: string[], url?: string, auth_token?: string}|null
     */
    private function toServerConfig(McpServerDefinition $definition): ?array
    {
        $configuration = $definition->getConfiguration();

        if (isset($configuration['url']) && is_string($configuration['url']) && $configuration['url'] !== '') {
            $config = ['transport' => 'http', 'url' => $configuration['url']];
            if (isset($configuration['auth_token']) && is_string($configuration['auth_token']) && $configuration['auth_token'] !== '') {
                $config['auth_token'] = $configuration['auth_token'];
            }

            return $config;
        }

        if (isset($configuration['command']) && is_string($configuration['command']) && $configuration['command'] !== '') {
            $config = ['transport' => 'stdio', 'command' => $configuration['command']];
            if (isset($configuration['arguments']) && is_array($configuration['arguments'])) {
                $config['arguments'] = $configuration['arguments'];
            }

            return $config;
        }

        return null;
    }
}
