<?php

namespace App\AI\Skills;

use App\Entity\ToolDefinition;
use App\Repository\ToolDefinitionRepository;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

#[Autoconfigure(tags: ['ai.skill_registry'])]
class DynamicSkillRegistry
{
    private ToolDefinitionRepository $toolDefinitionRepo;
    private array $tools = [];

    public function __construct(ToolDefinitionRepository $toolDefinitionRepo)
    {
        $this->toolDefinitionRepo = $toolDefinitionRepo;
        $this->loadTools();
    }

    /**
     * Loads all approved tools from the database.
     */
    public function loadTools(): void
    {
        $toolDefinitions = $this->toolDefinitionRepo->findBy(['status' => 'approved']);
        
        foreach ($toolDefinitions as $toolDefinition) {
            $this->tools[$toolDefinition->getName()] = $this->createToolFromDefinition($toolDefinition);
        }
    }

    /**
     * Creates a tool instance from a ToolDefinition entity.
     */
    private function createToolFromDefinition(ToolDefinition $toolDefinition): object
    {
        // This is a placeholder for the actual tool creation logic
        // In a real implementation, this would create a dynamic proxy or instance
        // based on the schema and parameters defined in the ToolDefinition
        
        return new class($toolDefinition) {
            private ToolDefinition $definition;

            public function __construct(ToolDefinition $definition)
            {
                $this->definition = $definition;
            }

            public function execute(string $prompt, string $userIdentifier): array
            {
                // Placeholder execution logic
                return [
                    'tool' => $this->definition->getName(),
                    'prompt' => $prompt,
                    'user' => $userIdentifier,
                    'result' => 'Executed ' . $this->definition->getName() . ' with prompt: ' . $prompt
                ];
            }

            public function getDefinition(): ToolDefinition
            {
                return $this->definition;
            }
        };
    }

    /**
     * Returns all available tools.
     */
    public function getAvailableTools(): array
    {
        return $this->tools;
    }

    /**
     * Returns a specific tool by name.
     */
    public function getTool(string $toolName): object
    {
        if (!isset($this->tools[$toolName])) {
            throw new \InvalidArgumentException(sprintf(
                'Tool "%s" not found or not approved.',
                $toolName
            ));
        }

        return $this->tools[$toolName];
    }

    /**
     * Adds a new tool to the registry.
     */
    public function addTool(ToolDefinition $toolDefinition): void
    {
        if ($toolDefinition->isApproved()) {
            $this->tools[$toolDefinition->getName()] = $this->createToolFromDefinition($toolDefinition);
        }
    }

    /**
     * Removes a tool from the registry.
     */
    public function removeTool(string $toolName): void
    {
        unset($this->tools[$toolName]);
    }
}
