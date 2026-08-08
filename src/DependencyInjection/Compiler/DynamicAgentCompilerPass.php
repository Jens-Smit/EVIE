<?php
// src/DependencyInjection/Compiler/DynamicAgentCompilerPass.php

namespace App\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * CompilerPass für die dynamische Registrierung von:
 * - Tools aus der ToolDefinition-Datenbank
 * - Sub-Agenten aus der Konfiguration
 * 
 * Wird beim Container-Build ausgelöst und registriert alle freigegebenen
 * Tools als Symfony AI Tools.
 */
final class DynamicAgentCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        // 1. Prüfe, ob die benötigten Services existieren
        if (!$container->has('doctrine')) {
            return;
        }

        // 2. Lade alle freigegebenen ToolDefinition-Entitäten
        $toolDefinitions = $this->loadApprovedToolDefinitions($container);

        // 3. Registriere jedes Tool als Service
        foreach ($toolDefinitions as $toolDef) {
            $this->registerDynamicTool($container, $toolDef);
        }

        // 4. Registriere Sub-Agenten (falls in der Konfiguration definiert)
        $this->registerSubAgents($container);
    }

    /**
     * Lädt alle freigegebenen ToolDefinition-Entitäten aus der Datenbank.
     * 
     * HINWEIS: Dies ist eine vereinfachte Version. In der Praxis sollte dies
     * über ein Repository oder eine Datenbankabfrage erfolgen. Da der CompilerPass
     * zur Build-Zeit läuft, ist ein direkter Datenbankzugriff nicht möglich.
     * 
     * Alternative Lösungen:
     * 1. Cache die ToolDefinitionen in einer Datei (z. B. var/cache/tool_definitions.php)
     * 2. Nutze einen Warmup-Process, der die Tools zur Laufzeit registriert
     * 3. Nutze einen Runtime-Service (z. B. DynamicSkillRegistry), der die Tools lädt
     * 
     * Für diese Implementierung gehen wir von einer Runtime-Registrierung aus,
     * daher ist dieser Teil hier nur als Platzhalter.
     */
    private function loadApprovedToolDefinitions(ContainerBuilder $container): array
    {
        // In einer echten Implementierung würde man hier die Tools aus der DB laden
        // Für jetzt: Leeres Array (die Tools werden zur Laufzeit registriert)
        return [];
    }

    /**
     * Registriert ein dynamisches Tool als Service.
     */
    private function registerDynamicTool(ContainerBuilder $container, array $toolDef): void
    {
        $toolName = $toolDef['name'] ?? 'unknown_tool';
        $serviceId = 'ai.tool.dynamic.' . $toolName;

        // Prüfe, ob der Service bereits existiert
        if ($container->has($serviceId)) {
            return;
        }

        // Erstelle eine Definition für das dynamische Tool
        $definition = new Definition();
        $definition->setClass('App\AI\Skills\Tool\DynamicTool');
        $definition->setArguments([
            new Reference('App\AI\Skills\Tool\DynamicToolFactory'),
            $toolName,
            $toolDef,
        ]);
        $definition->addTag('ai.tool');
        $definition->addTag('container.hot_path');

        // Registriere den Service
        $container->setDefinition($serviceId, $definition);
    }

    /**
     * Registriert Sub-Agenten aus der Konfiguration.
     */
    private function registerSubAgents(ContainerBuilder $container): void
    {
        // Lade die AI-Konfiguration
        if (!$container->hasParameter('ai.agent')) {
            return;
        }

        $agentConfig = $container->getParameter('ai.agent');

        // Registriere jeden Sub-Agenten als Service
        foreach ($agentConfig as $agentName => $config) {
            // Überspringe den Orchestrator
            if ($agentName === 'orchestrator') {
                continue;
            }

            $this->registerSubAgentService($container, $agentName, $config);
        }
    }

    /**
     * Registriert einen einzelnen Sub-Agenten als Service.
     */
    private function registerSubAgentService(
        ContainerBuilder $container,
        string $agentName,
        array $config
    ): void {
        $serviceId = 'ai.agent.' . $agentName;

        // Prüfe, ob der Service bereits existiert
        if ($container->has($serviceId)) {
            return;
        }

        // Erstelle eine Definition für den Sub-Agenten
        $definition = new Definition();
        $definition->setClass('Symfony\AI\Agent\Agent');
        $definition->setFactory([new Reference('ai.agent_factory'), 'create']);
        $definition->setArguments([
            [
                'name' => $agentName,
                'platform' => $config['platform'] ?? 'ai.platform.mistral',
                'model' => $config['model'] ?? 'mistral-small-latest',
                'prompt' => $config['prompt'] ?? '',
                'tools' => $config['tools'] ?? [],
            ],
        ]);
        $definition->addTag('ai.agent');
        $definition->addTag('container.hot_path');

        // Registriere den Service
        $container->setDefinition($serviceId, $definition);
    }
}
