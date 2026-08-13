<?php

namespace App\AI\Skills;

use App\Entity\ToolDefinition;
use App\AI\Skills\Tool\DynamicTool;
use Psr\Log\LoggerInterface;

/**
 * Registriert und verwaltet DynamicTools
 */
class SubAgentRegistry
{
    private array $tools = [];
    private array $toolDefinitions = [];

    public function __construct(
        private SubAgentToolFactory $toolFactory,
        private SubAgentDefinitionLoader $definitionLoader,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Registriert ein neues Tool
     */
    public function registerTool(DynamicTool $tool, ToolDefinition $definition): void
    {
        $this->tools[$tool->getName()] = $tool;
        $this->toolDefinitions[$tool->getName()] = $definition;
        $this->logger->info('Tool registriert', ['tool_name' => $tool->getName()]);
    }

    /**
     * Entfernt ein Tool
     */
    public function unregisterTool(string $toolName): void
    {
        if (isset($this->tools[$toolName])) {
            unset($this->tools[$toolName]);
            unset($this->toolDefinitions[$toolName]);
            $this->logger->info('Tool deregistriert', ['tool_name' => $toolName]);
        }
    }

    /**
     * Lädt alle approved Tools aus der DB und registriert sie
     */
    public function loadAndRegisterApprovedTools(): void
    {
        $definitions = $this->definitionLoader->loadAllApproved();
        foreach ($definitions as $definition) {
            try {
                $tool = $this->toolFactory->createFromDefinition($definition);
                $this->registerTool($tool, $definition);
            } catch (\Exception $e) {
                $this->logger->error('Fehler beim Registrieren des Tools', [
                    'tool_id' => $definition->getId(),
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Gibt ein Tool zurück
     */
    public function getTool(string $name): ?DynamicTool
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * Gibt alle registrierten Tools zurück
     */
    public function getAllTools(): array
    {
        return $this->tools;
    }

    /**
     * Gibt die ToolDefinition für ein Tool zurück
     */
    public function getToolDefinition(string $name): ?ToolDefinition
    {
        return $this->toolDefinitions[$name] ?? null;
    }

    /**
     * Prüft ob ein Tool registriert ist
     */
    public function hasTool(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /**
     * Lädt Tools neu
     */
    public function reload(): void
    {
        $this->tools = [];
        $this->toolDefinitions = [];
        $this->loadAndRegisterApprovedTools();
    }
}
