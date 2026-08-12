<?php
// src/DependencyInjection/Compiler/AiMcpServersCompilerPass.php

namespace App\DependencyInjection\Compiler;

use App\Entity\McpServerDefinition;
use App\Repository\McpServerDefinitionRepository;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * CompilerPass für die dynamische Registrierung von MCP-Servern aus der Datenbank.
 * Lädt alle aktiven MCP-Server-Definitionen zur Compile-Time und registriert sie als Services.
 */
class AiMcpServersCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        // Prüfe, ob die benötigten Services existieren
        if (!$container->has('doctrine.orm.entity_manager') || 
            !$container->has(McpServerDefinitionRepository::class)) {
            return;
        }

        // Versuche, MCP-Server-Definitionen aus dem Cache zu laden
        // In der Praxis: Cache-Warmup-Command ausführen, um Definitionen zu laden
        $definitions = $this->getMcpServerDefinitionsFromCache($container);

        foreach ($definitions as $definition) {
            $this->registerMcpServerService($container, $definition);
        }
    }

    /**
     * Lädt MCP-Server-Definitionen aus dem Cache.
     * HINWEIS: In der Praxis sollte dies über ein Cache-Warmup-Command geschehen,
     * da die Datenbank zur Compile-Time nicht verfügbar ist.
     * @return McpServerDefinition[]
     */
    private function getMcpServerDefinitionsFromCache(ContainerBuilder $container): array
    {
        // Versuche, aus dem Cache zu laden (falls verfügbar)
        if ($container->has('cache.app') && $container->has('doctrine.orm.entity_manager')) {
            try {
                $cache = $container->get('cache.app');
                $cachedDefinitions = $cache->get('ai.mcp_server.definitions');
                
                if (is_array($cachedDefinitions)) {
                    return $cachedDefinitions;
                }
            } catch (\Exception $e) {
                // Cache nicht verfügbar oder Fehler
            }
        }

        // Fallback: Leeres Array (Definitionen werden zur Runtime geladen)
        return [];
    }

    /**
     * Registriert einen MCP-Server als Service.
     */
    private function registerMcpServerService(ContainerBuilder $container, McpServerDefinition $definition): void
    {
        $serviceId = 'ai.mcp.server.dynamic.' . $definition->getName();

        if ($container->has($serviceId)) {
            return; // Bereits registriert
        }

        $type = $definition->getType();
        $serviceIdForType = $this->getServiceIdForType($type);

        // Registriere den Service nur, wenn die Basis-Service existiert
        if ($container->has($serviceIdForType)) {
            $container->register($serviceId, $serviceIdForType)
                ->addTag('ai.mcp.server')
                ->addTag('container.hot_path')
                ->setPublic(true)
                ->setAutoconfigured(true)
                ->addMethodCall('setConfiguration', [$definition->getConfiguration()])
                ->addMethodCall('setAllowedTools', [$definition->getAllowedTools()])
                ->addMethodCall('setBlockedResources', [$definition->getBlockedResources()])
                ->addMethodCall('initialize');
        }
    }

    /**
     * Gibt die Service-ID für einen MCP-Server-Typ zurück.
     */
    private function getServiceIdForType(string $type): string
    {
        // Mapping von Typen zu Service-IDs
        $typeMap = [
            'filesystem' => 'ai.mcp.server.filesystem',
            'playwright' => 'ai.mcp.server.playwright',
            'github' => 'ai.mcp.server.github',
            'custom' => 'ai.mcp.server.custom',
        ];

        return $typeMap[$type] ?? 'ai.mcp.server.' . $type;
    }
}
