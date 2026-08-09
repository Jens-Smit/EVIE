<?php
// src/AI/Agent/EvieToolboxFactory.php

namespace App\AI\Agent;

use App\AI\Skills\DynamicSkillRegistry;
use App\AI\Skills\Tool\ToolInterface;
use App\Mcp\Toolbox\McpToolFactoryWrapper;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Agent\Toolbox\ToolFactory\ChainFactory;
use Symfony\AI\Agent\Toolbox\ToolFactory\ReflectionToolFactory;
use Symfony\AI\Agent\Toolbox\Tool\Subagent;

/**
 * Factory für die Toolbox des EVIE-Agenten.
 * Lädt jetzt auch dynamisch generierte Tools aus der Datenbank.
 * 
 * Verbesserungen:
 * - Unterstützung für Subagent-Tools
 * - Dynamische Tool-Registrierung
 * - Bessere Integration mit Symfony AI Bundle
 */
final class EvieToolboxFactory
{
    public function __construct(
        private readonly McpToolFactoryWrapper $mcpToolFactory,
        private readonly iterable $nativeTools,
        private readonly DynamicSkillRegistry $dynamicSkillRegistry,
        private readonly SubAgentFactory $subAgentFactory,
    ) {
    }

    /**
     * Erstellt eine Toolbox mit allen verfügbaren Tools:
     * - MCP-Tools
     * - Native PHP-Tools (via Reflection)
     * - Dynamisch generierte Tools aus der Datenbank
     * - Sub-Agenten als Tools
     */
    public function create(): Toolbox
    {
        $reflectionFactory = new ReflectionToolFactory();
        
        // 1. ChainFactory für MCP und Reflection Tools
        $chainFactory = new ChainFactory([
            $this->mcpToolFactory,
            $reflectionFactory,
        ]);

        // 2. Liste aller Tools zusammenstellen
        $allTools = [];
        
        // 2a. Native Tools (aus der DI-Container-Konfiguration)
        foreach ($this->nativeTools as $tool) {
            if ($tool instanceof ToolInterface) {
                $allTools[] = $tool;
            }
        }

        // 2b. Sub-Agenten als Tools hinzufügen
        foreach ($this->subAgentFactory->createAllSubAgentTools() as $subAgentTool) {
            $allTools[] = $subAgentTool;
        }

        // 2c. Dynamische Tools aus der Datenbank
        // Jedes Tool aus dem DynamicSkillRegistry wird als dynamisches Tool registriert
        foreach ($this->dynamicSkillRegistry->getAvailableTools() as $toolName => $metadata) {
            // Erstelle ein dynamisches Tool für jede ToolDefinition
            $dynamicTool = new class($toolName, $metadata) implements ToolInterface {
                private string $toolName;
                private array $metadata;

                public function __construct(string $toolName, array $metadata)
                {
                    $this->toolName = $toolName;
                    $this->metadata = $metadata;
                }

                public function getName(): string
                {
                    return $this->toolName;
                }

                public function getDescription(): string
                {
                    return $this->metadata['description'] ?? 'Dynamisch generiertes Tool';
                }

                public function __invoke(array $parameters = []): array
                {
                    // Diese Methode wird durch den DynamicToolDispatcher implementiert
                    // Hier nur ein Platzhalter
                    return [
                        'status' => 'error',
                        'message' => 'Tool-Ausführung muss über DynamicToolDispatcher erfolgen',
                    ];
                }
            };

            $allTools[] = $dynamicTool;
        }

        // 3. Toolbox mit allen Tools erstellen
        return new Toolbox($allTools, $chainFactory);
    }

    /**
     * Erstellt eine Toolbox speziell für den Orchestrator-Agenten.
     * Enthält nur die Tools, die der Orchestrator benötigt.
     */
    public function createOrchestratorToolbox(): Toolbox
    {
        $reflectionFactory = new ReflectionToolFactory();
        $chainFactory = new ChainFactory([
            $this->mcpToolFactory,
            $reflectionFactory,
        ]);

        $orchestratorTools = [];

        // 1. MCP Tools
        // Diese werden über die ChainFactory verfügbar sein

        // 2. Sub-Agenten als Tools
        foreach ($this->subAgentFactory->createAllSubAgentTools() as $subAgentTool) {
            $orchestratorTools[] = $subAgentTool;
        }

        // 3. Dynamische Tools
        foreach ($this->dynamicSkillRegistry->getAvailableTools() as $toolName => $metadata) {
            $dynamicTool = new class($toolName, $metadata) implements ToolInterface {
                private string $toolName;
                private array $metadata;

                public function __construct(string $toolName, array $metadata)
                {
                    $this->toolName = $toolName;
                    $this->metadata = $metadata;
                }

                public function getName(): string
                {
                    return $this->toolName;
                }

                public function getDescription(): string
                {
                    return $this->metadata['description'] ?? 'Dynamisch generiertes Tool';
                }

                public function __invoke(array $parameters = []): array
                {
                    return [
                        'status' => 'error',
                        'message' => 'Tool-Ausführung muss über DynamicToolDispatcher erfolgen',
                    ];
                }
            };
            $orchestratorTools[] = $dynamicTool;
        }

        return new Toolbox($orchestratorTools, $chainFactory);
    }

    /**
     * Erstellt eine Toolbox für einen bestimmten Sub-Agenten.
     */
    public function createSubAgentToolbox(AgentInterface $subAgent): Toolbox
    {
        $reflectionFactory = new ReflectionToolFactory();
        $chainFactory = new ChainFactory([
            $this->mcpToolFactory,
            $reflectionFactory,
        ]);

        // Sub-Agenten-spezifische Tools
        $subAgentTools = [];

        // Füge alle Tools hinzu, die für diesen Sub-Agenten relevant sind
        // Dies könnte basierend auf der Rolle des Sub-Agenten gefiltert werden
        foreach ($this->nativeTools as $tool) {
            if ($tool instanceof ToolInterface) {
                $subAgentTools[] = $tool;
            }
        }

        return new Toolbox($subAgentTools, $chainFactory);
    }
}