<?php
// src/AI/Agent/SubAgentFactory.php

namespace App\AI\Agent;

use App\Entity\SubAgentDefinition;
use App\Entity\ToolDefinition;
use App\Repository\SubAgentDefinitionRepository;
use App\Repository\ToolDefinitionRepository;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\InputProcessor\SystemPromptInputProcessor;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * Factory für die dynamische und statische Erstellung von Sub-Agenten.
 * Unterstützt:
 * - Dynamisches Laden aus der Datenbank (SubAgentDefinition)
 * - Fallback zu statischer Konfiguration (ai.yaml)
 * - Registrierung als Tools für den Orchestrator
 * - Lazy-Loading für Runtime-Registrierung
 * - Implementiert SubAgentFactoryInterface zur Vermeidung zirkulärer Abhängigkeiten
 * 
 * @implements SubAgentFactoryInterface
 */
final class SubAgentFactory implements SubAgentFactoryInterface
{
    private PlatformInterface $platform;
    private ToolDefinitionRepository $toolDefinitionRepo;
    
    /** @var \App\AI\Skills\DynamicSkillRegistryInterface|null */
    private $dynamicSkillRegistry = null;
    
    private LoggerInterface $logger;
    private ContainerInterface $container;
    private SubAgentDefinitionRepository $subAgentDefinitionRepo;
    private ParameterBagInterface $params;

    public function __construct(
        PlatformInterface $platform,
        ToolDefinitionRepository $toolDefinitionRepo,
        LoggerInterface $logger,
        ContainerInterface $container,
        SubAgentDefinitionRepository $subAgentDefinitionRepo,
        ParameterBagInterface $params
    ) {
        $this->platform = $platform;
        $this->toolDefinitionRepo = $toolDefinitionRepo;
        $this->logger = $logger;
        $this->container = $container;
        $this->subAgentDefinitionRepo = $subAgentDefinitionRepo;
        $this->params = $params;
    }

    /**
     * Setzt das DynamicSkillRegistry (für Setter Injection zur Vermeidung zirkulärer Abhängigkeiten).
     */
    public function setDynamicSkillRegistry(\App\AI\Skills\DynamicSkillRegistryInterface $dynamicSkillRegistry): void
    {
        $this->dynamicSkillRegistry = $dynamicSkillRegistry;
    }

    /**
     * Erstellt einen Sub-Agenten basierend auf einer Definition aus der Datenbank.
     */
    public function createFromDefinition(SubAgentDefinition $definition): AgentInterface
    {
        $name = $definition->getName();
        $className = $definition->getClassName();
        $configuration = $definition->getConfiguration();

        $this->logger->info('Erstelle Sub-Agenten aus Datenbank-Definition', [
            'name' => $name,
            'class' => $className,
        ]);

        // 1. Prüfe, ob die Klasse existiert und ein AgentInterface implementiert
        if (class_exists($className) && is_subclass_of($className, AgentInterface::class)) {
            // Direkte Instanzierung, wenn es sich um eine konkrete Klasse handelt
            $subAgent = $this->container->get($className);
            if ($subAgent instanceof AgentInterface) {
                $this->registerAsTool($name, $definition->getDescription(), $subAgent);
                return $subAgent;
            }
        }

        // 2. Falls nicht, erstelle einen generischen Agenten mit der Konfiguration
        $model = $configuration['model'] ?? 'mistral-large-latest';
        $role = $configuration['role'] ?? $name;

        $subAgent = new Agent(
            platform: $this->platform,
            model: $model,
            name: $name,
            inputProcessors: [new SystemPromptInputProcessor($this->generatePromptForRole($role))],
        );

        $this->registerAsTool($name, $definition->getDescription(), $subAgent);

        $this->logger->info('Sub-Agent aus Definition erstellt', [
            'name' => $name,
            'model' => $model,
            'role' => $role,
        ]);

        return $subAgent;
    }

    /**
     * Erstellt alle aktiven Sub-Agenten aus der Datenbank.
     */
    public function createAllFromDatabase(): array
    {
        $definitions = $this->subAgentDefinitionRepo->findAllActive();
        $subAgents = [];

        foreach ($definitions as $definition) {
            try {
                $subAgent = $this->createFromDefinition($definition);
                $subAgents[$definition->getName()] = $subAgent;
            } catch (\Exception $e) {
                $this->logger->error('Fehler beim Laden des Sub-Agenten aus Definition', [
                    'name' => $definition->getName(),
                    'error' => $e->getMessage(),
                ]);
                continue;
            }
        }

        return $subAgents;
    }

    /**
     * Lädt alle aktiven Sub-Agenten aus der Datenbank und registriert sie als Tools.
     * Dies ist die Lazy-Loading-Alternative für den Fall, dass der CompilerPass nicht funktioniert.
     * Wird zur Runtime aufgerufen (z. B. in einem EventListener oder Command).
     */
    public function registerAllFromDatabase(): void
    {
        $definitions = $this->subAgentDefinitionRepo->findAllActive();
        
        foreach ($definitions as $definition) {
            try {
                $subAgent = $this->createFromDefinition($definition);
                
                // Erstelle eine ToolDefinition für den Sub-Agenten
                $toolDefinition = $this->createToolDefinitionForSubAgent($definition, $subAgent);
                
                // Registriere die ToolDefinition im DynamicSkillRegistry
                if ($this->dynamicSkillRegistry !== null) {
                    $this->dynamicSkillRegistry->addTool($toolDefinition);
                }

                $this->logger->info('Sub-Agent aus Datenbank registriert', [
                    'name' => $definition->getName(),
                    'class' => $definition->getClassName(),
                ]);
            } catch (\Exception $e) {
                $this->logger->error('Fehler beim Registrieren des Sub-Agenten aus Datenbank', [
                    'name' => $definition->getName(),
                    'error' => $e->getMessage(),
                ]);
                continue;
            }
        }

        $this->logger->info(sprintf(
            '%d Sub-Agenten aus Datenbank registriert (Lazy-Loading).',
            count($definitions)
        ));
    }

    /**
     * Erstellt eine ToolDefinition für einen Sub-Agenten.
     */
    private function createToolDefinitionForSubAgent(SubAgentDefinition $definition, AgentInterface $subAgent): ToolDefinition
    {
        $toolDefinition = new ToolDefinition();
        $toolDefinition->setName('sub_agent_' . $definition->getName());
        $toolDefinition->setDescription($definition->getDescription());
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

        return $toolDefinition;
    }

    /**
     * Erstellt einen Sub-Agenten basierend auf einem Namen.
     * 1. Versucht, aus der Datenbank zu laden
     * 2. Fallback: Statische Erstellung
     */
    public function createByName(string $name): AgentInterface
    {
        // 1. Versuche, aus der Datenbank zu laden
        $definition = $this->subAgentDefinitionRepo->findOneByName($name);
        if ($definition !== null) {
            return $this->createFromDefinition($definition);
        }

        // 2. Fallback: Statische Erstellung
        return $this->createFromStaticConfig($name);
    }

    /**
     * Erstellt einen Sub-Agenten aus der statischen Konfiguration.
     */
    private function createFromStaticConfig(string $name): AgentInterface
    {
        $this->logger->info('Erstelle Sub-Agenten aus statischer Konfiguration', [
            'name' => $name,
        ]);

        $model = 'mistral-large-latest';
        $role = $name;

        $subAgent = new Agent(
            platform: $this->platform,
            model: $model,
            name: $name,
            inputProcessors: [new SystemPromptInputProcessor($this->generatePromptForRole($role))],
        );

        $this->registerAsTool($name, 'Sub-Agent für ' . $role, $subAgent);

        return $subAgent;
    }

    /**
     * Registriert einen neuen Sub-Agenten dynamisch in der Datenbank.
     */
    public function registerSubAgent(SubAgentDefinition $definition): void
    {
        $entityManager = $this->container->get('doctrine.orm.entity_manager');
        $entityManager->persist($definition);
        $entityManager->flush();

        $this->logger->info('Sub-Agenten-Definition registriert', [
            'name' => $definition->getName(),
            'class' => $definition->getClassName(),
        ]);
    }

    /**
     * Erstellt einen neuen Sub-Agenten mit dem gegebenen Namen und der Rolle.
     * (Bestehende Methode für Abwärtskompatibilität)
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

        $subAgent = new Agent(
            platform: $this->platform,
            model: $model,
            name: $name,
            inputProcessors: [new SystemPromptInputProcessor($this->generatePromptForRole($role))],
        );

        $this->registerAsTool($name, 'Sub-Agent für ' . $role, $subAgent);

        $this->logger->info('Sub-Agent erstellt', [
            'name' => $name,
        ]);

        return $subAgent;
    }

    /**
     * Erstellt einen Sub-Agenten als Subagent-Tool für den Orchestrator.
     */
    public function createSubAgentTool(
        string $name,
        string $role,
        string $model = 'mistral-large-latest',
        array $tools = [],
    ): \Symfony\AI\Agent\Toolbox\Tool\Subagent {
        $this->logger->info('Erstelle SubAgent-Tool für Orchestrator', [
            'name' => $name,
            'role' => $role,
        ]);

        $subAgent = new Agent(
            platform: $this->platform,
            model: $model,
            name: $name,
            inputProcessors: [new SystemPromptInputProcessor($this->generatePromptForRole($role))],
        );

        $subAgentTool = new \Symfony\AI\Agent\Toolbox\Tool\Subagent($subAgent);

        $this->registerToolDefinition($name, $role);

        $this->logger->info('SubAgent-Tool registriert', [
            'tool_name' => 'sub_agent_' . $name,
            'agent_name' => $name,
        ]);

        return $subAgentTool;
    }

    /**
     * Registriert eine Tool-Definition für einen Sub-Agenten.
     */
    private function registerToolDefinition(string $name, string $role): void
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
        if ($this->dynamicSkillRegistry !== null) {
            $this->dynamicSkillRegistry->addTool($toolDefinition);
        }
    }

    /**
     * Registriert den Sub-Agenten als Tool für den Orchestrator.
     */
    private function registerAsTool(string $name, string $description, AgentInterface $agent): void
    {
        $toolDefinition = new ToolDefinition();
        $toolDefinition->setName('sub_agent_' . $name);
        $toolDefinition->setDescription($description);
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
        if ($this->dynamicSkillRegistry !== null) {
            $this->dynamicSkillRegistry->addTool($toolDefinition);
        }
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
     * Gibt alle verfügbaren Sub-Agenten zurück (statisch + dynamisch)
     */
    public function getAvailableSubAgents(): array
    {
        // 1. Lade dynamische Sub-Agenten aus der DB
        $dynamicSubAgents = $this->createAllFromDatabase();

        // 2. Füge statische Sub-Agenten hinzu (falls nicht in DB)
        $staticSubAgents = [
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

        // 3. Merge: Dynamische Sub-Agenten überschreiben statische
        return array_merge($staticSubAgents, $dynamicSubAgents);
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
