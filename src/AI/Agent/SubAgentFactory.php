<?php
// src/AI/Agent/SubAgentFactory.php

namespace App\AI\Agent;

use App\Entity\ToolDefinition;
use App\Repository\ToolDefinitionRepository;
use App\AI\Skills\DynamicSkillRegistry;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\AgentFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Factory für die dynamische Erstellung von Sub-Agenten.
 * Sub-Agenten werden als Tools für den Orchestrator registriert und können
 * spezifische Aufgaben übernehmen.
 */
final readonly class SubAgentFactory
{
    public function __construct(
        private AgentFactoryInterface $agentFactory,
        private ToolDefinitionRepository $toolDefinitionRepo,
        private DynamicSkillRegistry $dynamicSkillRegistry,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Erstellt einen neuen Sub-Agenten mit dem gegebenen Namen und der Rolle.
     */
    public function createSubAgent(
        string $name,
        string $role,
        string $model = 'mistral-small-latest',
        array $tools = [],
        string $platformService = 'ai.platform.mistral',
    ): AgentInterface {
        $this->logger->info('Erstelle neuen Sub-Agenten', [
            'name' => $name,
            'role' => $role,
            'tools' => $tools,
        ]);

        // 1. Agent-Konfiguration erstellen
        $agentConfig = [
            'name' => $name,
            'role' => $role,
            'platform' => $platformService,
            'model' => $model,
            'prompt' => $this->generatePromptForRole($role),
            'tools' => $this->resolveTools($tools),
        ];

        // 2. Agent erstellen
        $agent = $this->agentFactory->create($agentConfig);

        // 3. Sub-Agent im DynamicSkillRegistry registrieren (als Tool für den Orchestrator)
        $this->registerAsTool($name, $role, $agent);

        $this->logger->info('Sub-Agent erstellt', [
            'name' => $name,
            'agent_class' => get_class($agent),
        ]);

        return $agent;
    }

    /**
     * Löst die Tool-Referenzen auf (kann Tool-Namen oder ToolDefinition-IDs sein).
     */
    private function resolveTools(array $tools): array
    {
        $resolvedTools = [];

        foreach ($tools as $tool) {
            // Falls es ein Tool-Name ist
            if (is_string($tool)) {
                if ($this->dynamicSkillRegistry->hasTool($tool)) {
                    $resolvedTools[] = $this->dynamicSkillRegistry->getTool($tool);
                } else {
                    $this->logger->warning('Tool nicht gefunden', ['tool_name' => $tool]);
                }
            }
            // Falls es eine ToolDefinition-ID ist
            elseif (is_int($tool)) {
                $toolDefinition = $this->toolDefinitionRepo->find($tool);
                if ($toolDefinition && $toolDefinition->isApproved()) {
                    $resolvedTools[] = $toolDefinition;
                }
            }
            // Falls es bereits ein ToolInterface ist
            elseif ($tool instanceof \App\AI\Skills\Tool\ToolInterface) {
                $resolvedTools[] = $tool;
            }
        }

        return $resolvedTools;
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
            role: 'research',
            tools: ['web_search', 'file_read', 'text_analysis'],
        );
    }

    /**
     * Erstellt einen Sub-Agenten für Datenanalyse.
     */
    public function createDataAnalysisAgent(): AgentInterface
    {
        return $this->createSubAgent(
            name: 'data_analyst',
            role: 'analysis',
            tools: ['data_processing', 'statistics', 'visualization'],
        );
    }

    /**
     * Gibt alle verfügbaren Sub-Agenten zurück.
     */
    public function getAvailableSubAgents(): array
    {
        // Hier könnten alle in der DB gespeicherten Sub-Agenten zurückgegeben werden
        // Für jetzt: Standard-Sub-Agenten
        return [
            'website_researcher' => $this->createWebsiteResearchAgent(),
            'data_analyst' => $this->createDataAnalysisAgent(),
        ];
    }
}