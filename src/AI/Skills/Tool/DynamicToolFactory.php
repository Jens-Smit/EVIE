<?php

namespace AppAISkillsTool;

use AppEntityToolDefinition;
use AppAISkillsSubAgentDefinitionLoader;
use AppAISkillsSubAgentToolFactory;
use AppAISkillsSubAgentRegistry;
use AppAISkillsSubAgentPromptResolver;
use PsrLogLoggerInterface;

/**
 * Factory für DynamicTools - vereinfacht nach Phase 2
 * Verantwortlichkeiten aufgeteilt in:
 * - SubAgentDefinitionLoader (Laden von Definitionen)
 * - SubAgentToolFactory (Erstellen von Tools)
 * - SubAgentRegistry (Verwalten von Tools)
 * - SubAgentPromptResolver (Prompt-Auflösung)
 */
class DynamicToolFactory
{
    public function __construct(
        private SubAgentDefinitionLoader $definitionLoader,
        private SubAgentToolFactory $toolFactory,
        private SubAgentRegistry $registry,
        private SubAgentPromptResolver $promptResolver,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Erstellt und registriert ein Tool aus einer Definition
     */
    public function createAndRegisterTool(ToolDefinition $definition): DynamicTool
    {
        $tool = $this->toolFactory->createFromDefinition($definition);
        $this->registry->registerTool($tool, $definition);
        return $tool;
    }

    /**
     * Erstellt Tools aus einer Definition-ID
     */
    public function createFromDefinitionId(int $id): ?DynamicTool
    {
        $definition = $this->definitionLoader->loadFromDatabase($id);
        if (!$definition) {
            $this->logger->warning('Tool-Definition nicht gefunden', ['id' => $id]);
            return null;
        }
        return $this->createAndRegisterTool($definition);
    }

    /**
     * Lädt und registriert alle approved Tools
     */
    public function loadAndRegisterAllApproved(): void
    {
        $this->registry->loadAndRegisterApprovedTools();
    }

    /**
     * Gibt den Prompt für ein Tool zurück
     */
    public function getToolPrompt(ToolDefinition $definition): string
    {
        return $this->promptResolver->createToolPrompt($definition);
    }

    /**
     * Entfernt ein Tool
     */
    public function removeTool(string $toolName): void
    {
        $this->registry->unregisterTool($toolName);
    }

    /**
     * Lädt Tools neu
     */
    public function reload(): void
    {
        $this->registry->reload();
    }

    /**
     * Gibt ein Tool zurück
     */
    public function getTool(string $name): ?DynamicTool
    {
        return $this->registry->getTool($name);
    }

    /**
     * Gibt alle Tools zurück
     */
    public function getAllTools(): array
    {
        return $this->registry->getAllTools();
    }

    /**
     * Prüft ob ein Tool existiert
     */
    public function hasTool(string $name): bool
    {
        return $this->registry->hasTool($name);
    }
}
