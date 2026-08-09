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
        string $model = 'mistral-small-latest',
        array $tools = [],
    ): AgentInterface {
        $this->logger->info('Erstelle neuen Sub-Agenten mit Symfony AI Subagent', [
            'name' => $name,
            'role' => $role,
            'tools' => $tools,
        ]);

        // Erstelle den Sub-Agenten
        $subAgent = new Agent(
            platform: $this->platform,
            model: $model,
            name: $name,
            prompt: $this->generatePromptForRole($role),
        );

        // Sub-Agent im DynamicSkillRegistry als Tool registrieren
        $this->registerAsTool($name, $role, $subAgent);

        $this->logger->info('Sub-Agent mit Symfony AI Subagent erstellt', [
            'name' => $name,
            'agent_class' => get_class($subAgent),
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
        string $model = 'mistral-small-latest',
        array $tools = [],
    ): Subagent {
        $this->logger->info('Erstelle SubAgent-Tool für Orchestrator', [
            'name' => $name,
            'role' => $role,
        ]);

        // Erstelle den Sub-Agenten
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
        $toolDefinition->setDescription(sprintf(
            'Sub-Agent für %s. Kann folgende Aufgaben übernehmen: %s',
            $role,
            implode(', ', $this->getCapabilitiesForRole($role))
        ));
        $toolDefinition->setStatus('approved'); // Sub-Agenten sind sofort freigegeben
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

        // Füge zum DynamicSkillRegistry hinzu
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
            'research' => 'Du bist ein Recherche-Spezialist. Deine Aufgabe ist es, Informationen zu finden, zu analysieren und zusammenzufassen. Nutze die verfügbaren Tools, um Webseiten zu durchsuchen, Daten zu extrahieren und Berichte zu erstellen.',
            'analysis' => 'Du bist ein Datenanalyst. Deine Aufgabe ist es, Daten zu analysieren, Muster zu erkennen und Erkenntnisse zu liefern. Nutze mathematische und statistische Tools, um komplexe Analysen durchzuführen.',
            'support' => 'Du bist ein Support-Agent. Deine Aufgabe ist es, User bei Problemen zu helfen, Fragen zu beantworten und Lösungen anzubieten. Sei freundlich und hilfsbereit.',
            'coding' => 'Du bist ein Code-Assistent. Deine Aufgabe ist es, Code zu analysieren, zu verbessern und zu generieren. Nutze Tools für Code-Analyse, Testing und Deployment.',
            'writing' => 'Du bist ein Schreib-Assistent. Deine Aufgabe ist es, Texte zu verfassen, zu verbessern und zu korrigieren. Achte auf Klarheit, Präzision und gute Struktur.',
            'website_researcher' => 'Du bist ein spezialisierter Sub-Agent für Webseiten-Recherche und Inhaltszusammenfassung. Durchsuche Webseiten nach spezifischen Informationen wie Impressum, Kontakte, Geschäftszweck, Standort und Branche. Fasse Inhalte strukturiert zusammen.',
            'data_analyst' => 'Du bist ein spezialisierter Sub-Agent für Datenanalyse und statistische Auswertungen. Analysiere Daten, erkenne Muster und liefere Erkenntnisse.',
            'code_assistant' => 'Du bist ein spezialisierter Sub-Agent für Code-Analyse, Generierung und Review. Analysiere Code, schlage Verbesserungen vor und generiere neuen Code.',
            'document_processor' => 'Du bist ein spezialisierter Sub-Agent für Dokumentenverarbeitung. Verarbeite PDFs, Excel-Dateien und andere Dokumente.',
        ];

        return $rolePrompts[$role] ?? sprintf(
            'Du bist ein Sub-Agent mit der Rolle: %s. Führe die zugewiesenen Aufgaben präzise und effizient aus.',
            $role
        );
    }

    /**
     * Registriert den Sub-Agenten als Tool für den Orchestrator.
     */
    private function registerAsTool(string $name, string $role, AgentInterface $agent): void
    {
        // Erstelle eine ToolDefinition für den Sub-Agenten
        $toolDefinition = new ToolDefinition();
        $toolDefinition->setName('sub_agent_' . $name);
        $toolDefinition->setDescription(sprintf(
            'Sub-Agent für %s. Kann folgende Aufgaben übernehmen: %s',
            $role,
            implode(', ', $this->getCapabilitiesForRole($role))
        ));
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
        $toolDefinition->setStatus('approved'); // Sub-Agenten sind sofort freigegeben

        // Speichere in der Datenbank
        $this->toolDefinitionRepo->save($toolDefinition, true);

        // Füge zum DynamicSkillRegistry hinzu
        $this->dynamicSkillRegistry->addTool($toolDefinition);

        $this->logger->info('Sub-Agent als Tool registriert', [
            'tool_name' => $toolDefinition->getName(),
            'agent_name' => $name,
        ]);
    }

    /**
     * Gibt die Fähigkeiten für eine Rolle zurück.
     */
    private function getCapabilitiesForRole(string $role): array
    {
        $capabilities = [
            'research' => ['Webseiten durchsuchen', 'Daten extrahieren', 'Recherche durchführen', 'Zusammenfassungen erstellen'],
            'analysis' => ['Daten analysieren', 'Statistiken berechnen', 'Muster erkennen', 'Berichte erstellen'],
            'support' => ['Fragen beantworten', 'Probleme lösen', 'Anleitungen geben', 'Fehler analysieren'],
            'coding' => ['Code analysieren', 'Code generieren', 'Tests durchführen', 'Deployment unterstützen'],
            'writing' => ['Texte verfassen', 'Texte korrigieren', 'Inhalte strukturieren', 'Übersetzungen erstellen'],
            'website_researcher' => [
                'Webseiten durchsuchen und analysieren',
                'Impressum extrahieren',
                'Kontaktdaten identifizieren',
                'Geschäftszweck ermitteln',
                'Standortinformationen finden',
                'Branchenzuordnung vornehmen',
                'Inhalte zusammenfassen'
            ],
            'data_analyst' => ['Daten analysieren', 'Statistiken berechnen', 'Muster erkennen', 'Berichte erstellen'],
            'code_assistant' => ['Code analysieren', 'Code generieren', 'Tests durchführen', 'Code review'],
            'document_processor' => ['PDFs verarbeiten', 'Excel-Dateien analysieren', 'Dokumente extrahieren', 'Inhalte strukturieren'],
        ];

        return $capabilities[$role] ?? ['Allgemeine Aufgaben ausführen'];
    }

    /**
     * Erstellt einen Sub-Agenten für Website-Recherche (spezifisches Beispiel).
     */
    public function createWebsiteResearchAgent(): AgentInterface
    {
        return $this->createSubAgent(
            name: 'website_researcher',
            role: 'website_researcher',
            model: 'mistral-large-latest', // Stärkeres Modell für Recherche
        );
    }

    /**
     * Erstellt einen Sub-Agenten für Datenanalyse.
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
     * Erstellt einen Sub-Agenten für Code-Assistenz.
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
     * Erstellt einen Sub-Agenten für Dokumentenverarbeitung.
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
     * Gibt alle verfügbaren Sub-Agenten zurück.
     */
    public function getAvailableSubAgents(): array
    {
        return [
            'website_researcher' => $this->createWebsiteResearchAgent(),
            'data_analyst' => $this->createDataAnalysisAgent(),
            'code_assistant' => $this->createCodeAssistantAgent(),
            'document_processor' => $this->createDocumentProcessorAgent(),
        ];
    }

    /**
     * Erstellt alle Sub-Agenten als Tools für den Orchestrator.
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