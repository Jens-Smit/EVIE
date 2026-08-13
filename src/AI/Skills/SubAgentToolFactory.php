<?php

namespace AppAISkills;

use AppEntityToolDefinition;
use AppAISkillsToolDynamicTool;

/**
 * Erstellt DynamicTool-Instanz aus ToolDefinition
 */
class SubAgentToolFactory
{
    public function __construct(
        private SubAgentDefinitionLoader $definitionLoader
    ) {
    }

    /**
     * Erstellt ein DynamicTool aus einer ToolDefinition
     */
    public function createFromDefinition(ToolDefinition $definition): DynamicTool
    {
        return new DynamicTool(
            $definition->getName(),
            $definition->getDescription(),
            $definition->getSchema(),
            $definition->getExecutorType(),
            $definition->getExecutorConfig() ?? [],
            $definition->getSecurityPolicy() ?? [],
            $definition->getHitlPolicy() ?? [],
            $definition->getVersion() ?? '1.0'
        );
    }

    /**
     * Erstellt mehrere DynamicTools aus ToolDefinitions
     */
    public function createMultipleFromDefinitions(array $definitions): array
    {
        $tools = [];
        foreach ($definitions as $definition) {
            $tools[] = $this->createFromDefinition($definition);
        }
        return $tools;
    }
}
