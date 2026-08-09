<?php
// src/AI/Agent/SubAgentFactory.php

namespace App\AI\Agent;

use App\Entity\ToolDefinition;
use App\Repository\ToolDefinitionRepository;
use App\AI\Skills\DynamicSkillRegistry;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Toolbox\Tool\Subagent;
use Symfony\AI\Platform\PlatformInterface;

/**
 * Factory für die dynamische Erstellung von Sub-Agenten.
 * Verwendet die offizielle Symfony AI Subagent-Klasse.
 * Sub-Agenten werden als Tools für den Orchestrator registriert und können
 * spezifische Aufgaben übernehmen.
 */
final readonly class SubAgentFactory
{
    public function __construct(
        private PlatformInterface $platform,
        private ToolDefinitionRepository $toolDefinitionRepo,
        private DynamicSkillRegistry $dynamicSkillRegistry,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Erstellt einen neuen Sub-Agenten mit dem gegebenen Namen und der Rolle.
     * Verwendet die offizielle Symfony AI Subagent-Klasse.
     */
    public function createSubAgent(
        string $name,
        string $role,
        string $model = 'mistral-large-latest',
        array $tools = [],
    ): AgentInterface {
        $this->logger->info('Erstelle neuen Sub-Agenten', [
            'name' => $name,
            'role' => $role,
        ]);

        // Erstelle den Sub-Agenten MIT PROMPT!
        $subAgent = new Agent(
            platform: $this->platform,
            model: $model,
            name: $name,
            prompt: $this->generatePromptForRole($role),
        );

        // Sub-Agent im DynamicSkillRegistry als Tool registrieren
        $this->registerAsTool($name, $role, $subAgent);

        $this->logger->info('Sub-Agent erstellt', [
            'name' => $name,
        ]);

        return $subAgent;
    }

    /**
     * Erstellt einen Sub-Agenten als Subagent-Tool für den Orchestrator.
     * Dies ermöglicht dem Orchestrator, den Sub-Agenten direkt als Tool aufzurufen.
     */
    public function createSubAgentTool(
        string $name,
        string $role,
        string $model = 'mistral-large-latest',
        array $tools = [],
    ): Subagent {
        $this->logger->info('Erstelle SubAgent-Tool für Orchestrator', [
            'name' => $name,
            'role' => $role,
        ]);

        // Erstelle den Sub-Agenten MIT PROMPT!
        $subAgent = new Agent(
            platform: $this->platform,
            model: $model,
            name: $name,
            prompt: $this->generatePromptForRole($role),
        );

        // Erstelle ein Subagent-Tool
        $subAgentTool = new Subagent($subAgent);

        // Registriere in der Datenbank
        $toolDefinition = new ToolDefinition();
        $toolDefinition->setName('sub_agent_' . $name);
        $toolDefinition->setDescription('Sub-Agent für ' . $role);
        $toolDefinition->setStatus('approved');
        $toolDefinition->setSchema([
            'type' => 'object',
            'properties' => [
                'task' => [
                    'type' => 'string',
                    'description' => 'Die Aufgabe, die der Sub-Agent ausführen soll',
                ],
                'parameters' => [
                    'type' => 'object',
                    'description' => 'Zusätzliche Parameter für die Aufgabe',
                    'additionalProperties' => true,
                ],
            ],
            'required' => ['task'],
        ]);
        $toolDefinition->setParameters([
            ['name' => 'task', 'type' => 'string', 'required' => true, 'description' => 'Aufgabe für den Sub-Agenten'],
            ['name' => 'parameters', 'type' => 'object', 'required' => false, 'description' => 'Zusätzliche Parameter'],
        ]);

        $this->toolDefinitionRepo->save($toolDefinition, true);
        $this->dynamicSkillRegistry->addTool($toolDefinition);

        $this->logger->info('SubAgent-Tool registriert', [
            'tool_name' => $toolDefinition->getName(),
            'agent_name' => $name,
        ]);

        return $subAgentTool;
    }

    /**
     * Generiert einen System-Prompt basierend auf der Rolle.
     */
    private function generatePromptForRole(string $role): string
    {
        $rolePrompts = [
            'website_researcher' => 'Du bist ein spezialisierter Sub-Agent für Webseiten-Recherche. Deine Aufgabe: Durchsuche Webseiten nach Impressum, Kontakten, Geschäftszweck, Standort und Branche. Fasse die Informationen strukturiert zusammen. ANTWORTE IMMER IM FOLGENDEN JSON-FORMAT: {"type":"website_research_result","url":"URL","impressum":{"firma":"...","adresse":"...","kontakt":"..."},"kontakte":[{"name":"...","email":"...","telefon":"..."}],"geschäftszweck":"...","standort":"...","branche":"...","zusammenfassung":"..."}',
            'data_analyst' => 'Du bist ein Datenanalyst. Analysiere Daten und liefere Erkenntnisse. ANTWORTE IMMER IM JSON-FORMAT: {"type":"data_analysis_result","findings":["..."],"statistics":{},"summary":"..."}',
            'code_assistant' => 'Du bist ein Code-Assistent. Analysiere und generiere Code. ANTWORTE IMMER IM JSON-FORMAT: {"type":"code_result","analysis":"...","code":"..."}',
            'document_processor' => 'Du bist ein Dokumenten-Prozessor. Verarbeite Dokumente. ANTWORTE IMMER IM JSON-FORMAT: {"type":"document_result","data":{},"summary":"..."}',
            'communication_manager' => 'Du bist der Communication Manager von EVIE. Verwalte E-Mails, Nachrichten, LinkedIn und andere Kommunikation. ANTWORTE IMMER IM JSON-FORMAT: {"type":"communication_result","action":"send_email|read_emails|send_linkedin_message","status":"success|failed","details":{},"message":"..."}',
            'api_integration' => 'Du bist der API Integration Agent von EVIE. Binde externe APIs an, verwalte OAuth und Authentifizierung. ANTWORTE IMMER IM JSON-FORMAT: {"type":"api_result","action":"authenticate|call_api|get_data","endpoint":"...","method":"GET|POST|PUT|DELETE","status":"success|failed","data":{}}',
            'project_manager' => 'Du bist der Project Manager von EVIE. Verwalte Aufgaben, Termine, Ressourcen und Projekte. ANTWORTE IMMER IM JSON-FORMAT.',
            'finance_manager' => 'Du bist der Finance Manager von EVIE. Verwalte Buchhaltung, Rechnungen, Zahlungen. ANTWORTE IMMER IM JSON-FORMAT.',
            'hr_manager' => 'Du bist der HR Manager von EVIE. Verwalte Mitarbeiter, Gehälter, Verträge. ANTWORTE IMMER IM JSON-FORMAT.',
            'marketing_manager' => 'Du bist der Marketing Manager von EVIE. Verwalte Kampagnen, Social Media, Content. ANTWORTE IMMER IM JSON-FORMAT.',
            'ceo_assistant' => 'Du bist der CEO Assistant von EVIE. Entwickle Strategien, treffe Entscheidungen, priorisiere Aufgaben. Nutze andere Sub-Agenten für spezifische Aufgaben. ANTWORTE IMMER IM JSON-FORMAT: {"type":"strategy_result|decision|task_prioritization","strategy":{},"decision":{},"tasks":[]}',
        ];

        return $rolePrompts[$role] ?? 'Du bist ein Sub-Agent. Führe Aufgaben aus. ANTWORTE IMMER IM JSON-FORMAT: {"type":"result","content":"..."}';
    }

    /**
     * Registriert den Sub-Agenten als Tool für den Orchestrator.
     */
    private function registerAsTool(string $name, string $role, AgentInterface $agent): void
    {
        $toolDefinition = new ToolDefinition();
        $toolDefinition->setName('sub_agent_' . $name);
        $toolDefinition->setDescription('Sub-Agent für ' . $role);
        $toolDefinition->setStatus('approved');
        $toolDefinition->setSchema([
            'type' => 'object',
            'properties' => [
                'task' => [
                    'type' => 'string',
                    'description' => 'Die Aufgabe, die der Sub-Agent ausführen soll',
                ],
                'parameters' => [
                    'type' => 'object',
                    'description' => 'Zusätzliche Parameter für die Aufgabe',
                    'additionalProperties' => true,
                ],
            ],
            'required' => ['task'],
        ]);
        $toolDefinition->setParameters([
            ['name' => 'task', 'type' => 'string', 'required' => true, 'description' => 'Aufgabe für den Sub-Agenten'],
            ['name' => 'parameters', 'type' => 'object', 'required' => false, 'description' => 'Zusätzliche Parameter'],
        ]);

        $this->toolDefinitionRepo->save($toolDefinition, true);
        $this->dynamicSkillRegistry->addTool($toolDefinition);

        $this->logger->info('Sub-Agent als Tool registriert', [
            'tool_name' => $toolDefinition->getName(),
            'agent_name' => $name,
        ]);
    }

    /**
     * Erstellt einen Sub-Agenten für Website-Recherche
     */
    public function createWebsiteResearchAgent(): AgentInterface
    {
        return $this->createSubAgent(
            name: 'website_researcher',
            role: 'website_researcher',
            model: 'mistral-large-latest',
        );
    }

    /**
     * Erstellt einen Sub-Agenten für Datenanalyse
     */
    public function createDataAnalysisAgent(): AgentInterface
    {
        return $this->createSubAgent(
            name: 'data_analyst',
            role: 'data_analyst',
            model: 'mistral-large-latest',
        );
    }

    /**
     * Erstellt einen Sub-Agenten für Code-Assistenz
     */
    public function createCodeAssistantAgent(): AgentInterface
    {
        return $this->createSubAgent(
            name: 'code_assistant',
            role: 'code_assistant',
            model: 'mistral-large-latest',
        );
    }

    /**
     * Erstellt einen Sub-Agenten für Dokumentenverarbeitung
     */
    public function createDocumentProcessorAgent(): AgentInterface
    {
        return $this->createSubAgent(
            name: 'document_processor',
            role: 'document_processor',
            model: 'mistral-large-latest',
        );
    }

    /**
     * Erstellt einen Communication Manager Agent
     */
    public function createCommunicationManagerAgent(): AgentInterface
    {
        return $this->createSubAgent(
            name: 'communication_manager',
            role: 'communication_manager',
            model: 'mistral-large-latest',
        );
    }

    /**
     * Erstellt einen API Integration Agent
     */
    public function createApiIntegrationAgent(): AgentInterface
    {
        return $this->createSubAgent(
            name: 'api_integration',
            role: 'api_integration',
            model: 'mistral-large-latest',
        );
    }

    /**
     * Erstellt einen Project Manager Agent
     */
    public function createProjectManagerAgent(): AgentInterface
    {
        return $this->createSubAgent(
            name: 'project_manager',
            role: 'project_manager',
            model: 'mistral-large-latest',
        );
    }

    /**
     * Erstellt einen Finance Manager Agent
     */
    public function createFinanceManagerAgent(): AgentInterface
    {
        return $this->createSubAgent(
            name: 'finance_manager',
            role: 'finance_manager',
            model: 'mistral-large-latest',
        );
    }

    /**
     * Erstellt einen HR Manager Agent
     */
    public function createHrManagerAgent(): AgentInterface
    {
        return $this->createSubAgent(
            name: 'hr_manager',
            role: 'hr_manager',
            model: 'mistral-large-latest',
        );
    }

    /**
     * Erstellt einen Marketing Manager Agent
     */
    public function createMarketingManagerAgent(): AgentInterface
    {
        return $this->createSubAgent(
            name: 'marketing_manager',
            role: 'marketing_manager',
            model: 'mistral-large-latest',
        );
    }

    /**
     * Erstellt den CEO Assistant Agent
     */
    public function createCeoAssistantAgent(): AgentInterface
    {
        return $this->createSubAgent(
            name: 'ceo_assistant',
            role: 'ceo_assistant',
            model: 'mistral-large-latest',
        );
    }

    /**
     * Gibt alle verfügbaren Sub-Agenten zurück
     */
    public function getAvailableSubAgents(): array
    {
        return [
            'website_researcher' => $this->createWebsiteResearchAgent(),
            'data_analyst' => $this->createDataAnalysisAgent(),
            'code_assistant' => $this->createCodeAssistantAgent(),
            'document_processor' => $this->createDocumentProcessorAgent(),
            'communication_manager' => $this->createCommunicationManagerAgent(),
            'api_integration' => $this->createApiIntegrationAgent(),
            'project_manager' => $this->createProjectManagerAgent(),
            'finance_manager' => $this->createFinanceManagerAgent(),
            'hr_manager' => $this->createHrManagerAgent(),
            'marketing_manager' => $this->createMarketingManagerAgent(),
            'ceo_assistant' => $this->createCeoAssistantAgent(),
        ];
    }

    /**
     * Erstellt alle Sub-Agenten als Tools für den Orchestrator
     */
    public function createAllSubAgentTools(): array
    {
        $subAgentTools = [];
        
        foreach ($this->getAvailableSubAgents() as $name => $agent) {
            $subAgentTools[$name] = $this->createSubAgentTool(
                name: $name,
                role: $name,
                model: 'mistral-large-latest'
            );
        }
        
        return $subAgentTools;
    }
}
