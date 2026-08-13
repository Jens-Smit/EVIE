<?php

namespace App\AI\Agent;

use Symfony\AI\Agent\AgentInterface;
use App\Entity\SubAgentDefinition;
use Symfony\AI\Agent\Toolbox\Tool\Subagent;

/**
 * Interface für SubAgentFactory
 * 
 * Dieses Interface wird verwendet, um die zirkuläre Abhängigkeit zwischen
 * DynamicSkillRegistry, DynamicToolFactory und SubAgentFactory zu brechen.
 * 
 * @see ROADMAP_PHASE3.md Maßnahme 9: E2E-Test für Evolution-Flow
 */
interface SubAgentFactoryInterface
{
    /**
     * Erstellt einen Sub-Agenten basierend auf einer Definition aus der Datenbank.
     */
    public function createFromDefinition(SubAgentDefinition $definition): AgentInterface;

    /**
     * Erstellt alle aktiven Sub-Agenten aus der Datenbank.
     * 
     * @return array<string, AgentInterface>
     */
    public function createAllFromDatabase(): array;

    /**
     * Lädt alle aktiven Sub-Agenten aus der Datenbank und registriert sie als Tools.
     */
    public function registerAllFromDatabase(): void;

    /**
     * Erstellt einen Sub-Agenten basierend auf einem Namen.
     */
    public function createByName(string $name): AgentInterface;

    /**
     * Erstellt einen neuen Sub-Agenten mit dem gegebenen Namen und der Rolle.
     */
    public function createSubAgent(
        string $name,
        string $role,
        string $model = 'mistral-large-latest',
        array $tools = []
    ): AgentInterface;

    /**
     * Erstellt einen Sub-Agenten als Subagent-Tool für den Orchestrator.
     */
    public function createSubAgentTool(
        string $name,
        string $role,
        string $model = 'mistral-large-latest',
        array $tools = []
    ): Subagent;

    /**
     * Gibt alle verfügbaren Sub-Agenten zurück (statisch + dynamisch).
     * 
     * @return array<string, AgentInterface>
     */
    public function getAvailableSubAgents(): array;

    /**
     * Erstellt alle Sub-Agenten als Tools für den Orchestrator.
     * 
     * @return array<string, Subagent>
     */
    public function createAllSubAgentTools(): array;

    /**
     * Registriert einen neuen Sub-Agenten dynamisch in der Datenbank.
     */
    public function registerSubAgent(SubAgentDefinition $definition): void;

    /**
     * Erstellt einen Sub-Agenten für Website-Recherche.
     */
    public function createWebsiteResearchAgent(): AgentInterface;

    /**
     * Erstellt einen Sub-Agenten für Datenanalyse.
     */
    public function createDataAnalysisAgent(): AgentInterface;

    /**
     * Erstellt einen Sub-Agenten für Code-Assistenz.
     */
    public function createCodeAssistantAgent(): AgentInterface;

    /**
     * Erstellt einen Sub-Agenten für Dokumentenverarbeitung.
     */
    public function createDocumentProcessorAgent(): AgentInterface;

    /**
     * Erstellt einen Communication Manager Agent.
     */
    public function createCommunicationManagerAgent(): AgentInterface;

    /**
     * Erstellt einen API Integration Agent.
     */
    public function createApiIntegrationAgent(): AgentInterface;

    /**
     * Erstellt einen Project Manager Agent.
     */
    public function createProjectManagerAgent(): AgentInterface;

    /**
     * Erstellt einen Finance Manager Agent.
     */
    public function createFinanceManagerAgent(): AgentInterface;

    /**
     * Erstellt einen HR Manager Agent.
     */
    public function createHrManagerAgent(): AgentInterface;

    /**
     * Erstellt einen Marketing Manager Agent.
     */
    public function createMarketingManagerAgent(): AgentInterface;

    /**
     * Erstellt den CEO Assistant Agent.
     */
    public function createCeoAssistantAgent(): AgentInterface;

    /**
     * Setzt das DynamicSkillRegistry (für Setter Injection zur Vermeidung zirkulärer Abhängigkeiten).
     */
    public function setDynamicSkillRegistry(DynamicSkillRegistryInterface $dynamicSkillRegistry): void;
}
