<?php
// src/DependencyInjection/Compiler/AiSubAgentsCompilerPass.php

namespace App\DependencyInjection\Compiler;

use App\Entity\SubAgentDefinition;
use App\Repository\SubAgentDefinitionRepository;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * CompilerPass für die dynamische Registrierung von Sub-Agenten aus der Datenbank.
 * Lädt alle aktiven Sub-Agenten-Definitionen zur Compile-Time und registriert sie als Services.
 */
class AiSubAgentsCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        // Prüfe, ob die benötigten Services existieren
        if (!$container->has('doctrine.orm.entity_manager') || 
            !$container->has(SubAgentDefinitionRepository::class)) {
            return;
        }

        // Versuche, Sub-Agenten-Definitionen aus dem Cache zu laden
        // In der Praxis: Cache-Warmup-Command ausführen, um Definitionen zu laden
        $definitions = $this->getSubAgentDefinitionsFromCache($container);

        foreach ($definitions as $definition) {
            $this->registerSubAgentService($container, $definition);
        }
    }

    /**
     * Lädt Sub-Agenten-Definitionen aus dem Cache.
     * HINWEIS: In der Praxis sollte dies über ein Cache-Warmup-Command geschehen,
     * da die Datenbank zur Compile-Time nicht verfügbar ist.
     */
    private function getSubAgentDefinitionsFromCache(ContainerBuilder $container): array
    {
        // Versuche, aus dem Cache zu laden (falls verfügbar)
        if ($container->has('cache.app') && $container->has('doctrine.orm.entity_manager')) {
            try {
                $cache = $container->get('cache.app');
                $cachedDefinitions = $cache->get('ai.sub_agent.definitions');
                
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
     * Registriert einen Sub-Agenten als Service.
     */
    private function registerSubAgentService(ContainerBuilder $container, SubAgentDefinition $definition): void
    {
        $serviceId = 'ai.agent.dynamic.' . $definition->getName();

        if ($container->has($serviceId)) {
            return; // Bereits registriert
        }

        $className = $definition->getClassName();

        // Registriere den Service nur, wenn die Klasse existiert
        if (class_exists($className)) {
            $container->register($serviceId, $className)
                ->addTag('ai.agent')
                ->addTag('container.hot_path')
                ->setPublic(true)
                ->setAutoconfigured(true);
        }
    }
}
