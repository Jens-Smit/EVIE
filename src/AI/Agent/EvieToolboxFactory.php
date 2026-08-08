<?php
// src/AI/Agent/EvieToolboxFactory.php

namespace App\AI\Agent;

use App\AI\Skills\DynamicSkillRegistry;
use App\AI\Skills\Tool\DynamicToolFactory;
use App\Mcp\Toolbox\McpToolFactoryWrapper;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Agent\Toolbox\ToolFactory\ChainFactory;
use Symfony\AI\Agent\Toolbox\ToolFactory\ReflectionToolFactory;
use Symfony\AI\Agent\Tool\ToolInterface;

/**
 * Factory für die Toolbox des EVIE-Agenten.
 * Lädt jetzt auch dynamisch generierte Tools aus der Datenbank.
 */
final class EvieToolboxFactory
{
    public function __construct(
        private readonly McpToolFactoryWrapper $mcpToolFactory,
        private readonly iterable $nativeTools,
        private readonly DynamicSkillRegistry $dynamicSkillRegistry,
        private readonly DynamicToolFactory $dynamicToolFactory,
    ) {
    }

    /**
     * Erstellt eine Toolbox mit allen verfügbaren Tools:
     * - MCP-Tools
     * - Native PHP-Tools (via Reflection)
     * - Dynamisch generierte Tools aus der Datenbank
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

        // 2b. Dynamische Tools aus der Datenbank
        // Jedes Tool aus dem DynamicSkillRegistry wird als DynamicToolExecutor registriert
        foreach ($this->dynamicSkillRegistry->getAvailableTools() as $toolName => $metadata) {
            // Erstelle ein dynamisches Tool für jede ToolDefinition
            $dynamicTool = new class($toolName, $metadata, $this->dynamicToolFactory) implements ToolInterface {
                private string $toolName;
                private array $metadata;
                private DynamicToolFactory $dynamicToolFactory;

                public function __construct(
                    string $toolName,
                    array $metadata,
                    DynamicToolFactory $dynamicToolFactory
                ) {
                    $this->toolName = $toolName;
                    $this->metadata = $metadata;
                    $this->dynamicToolFactory = $dynamicToolFactory;
                }

                public function getName(): string
                {
                    return $this->toolName;
                }

                public function getDescription(): string
                {
                    return $this->metadata['description'] ?? 'Dynamisch generiertes Tool';
                }

                public function execute(array $parameters, \Symfony\AI\Agent\Context\AgentContext $context): string
                {
                    // Führe das Tool über die DynamicToolFactory aus
                    $tool = $this->dynamicToolFactory->getTool();
                    return $tool->execute([
                        'tool_name' => $this->toolName,
                        ...$parameters,
                    ], $context);
                }
            };

            $allTools[] = $dynamicTool;
        }

        // 3. Toolbox mit allen Tools erstellen
        return new Toolbox($chainFactory, $allTools);
    }
}