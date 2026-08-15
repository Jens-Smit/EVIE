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
use App\AI\Skills\DynamicSkillRegistryInterface; 
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
     */
    public function registerAllFromDatabase(): void
    {
        $definitions = $this->subAgentDefinitionRepo->findAllActive();
        
        foreach ($definitions as $definition) {
            try {
                $subAgent = $this->createFromDefinition($definition);
                
                $toolDefinition = $this->createToolDefinitionForSubAgent($definition, $subAgent);
                
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
        $toolDefinition->setCategory('sub_agent');
        $toolDefinition->setSchema([
            'type' => 'object',
            'properties' => [
                'task' => ['type' => 'string', 'description' => 'Die Aufgabe, die der Sub-Agent ausführen soll'],
                'parameters' => ['type' => 'object', 'description' => 'Zusätzliche Parameter', 'additionalProperties' => true],
            ],
            'required' => ['task'],
        ]);
        return $toolDefinition;
    }

    /**
     * Erstellt einen Sub-Agenten basierend auf einem Namen.
     */
    public function createByName(string $name): AgentInterface
    {
        $definition = $this->subAgentDefinitionRepo->findOneByName($name);
        if ($definition !== null) {
            return $this->createFromDefinition($definition);
        }
        return $this->createFromStaticConfig($name);
    }

    /**
     * Erstellt einen Sub-Agenten aus statischer Konfiguration.
     */
    private function createFromStaticConfig(string $name): AgentInterface
    {
        $this->logger->info('Erstelle Sub-Agenten aus statischer Konfiguration', ['name' => $name]);
        $subAgent = new Agent(
            platform: $this->platform,
            model: 'mistral-large-latest',
            name: $name,
            inputProcessors: [new SystemPromptInputProcessor($this->generatePromptForRole($name))],
        );
        $this->registerAsTool($name, 'Sub-Agent für ' . $name, $subAgent);
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
     * Erstellt einen neuen Sub-Agenten.
     */
    public function createSubAgent(
        string $name,
        string $role,
        string $model = 'mistral-large-latest',
        array $tools = []
    ): AgentInterface {
        $this->logger->info('Erstelle neuen Sub-Agenten', ['name' => $name, 'role' => $role]);
        $subAgent = new Agent(
            platform: $this->platform,
            model: $model,
            name: $name,
            inputProcessors: [new SystemPromptInputProcessor($this->generatePromptForRole($role))],
        );
        $this->registerAsTool($name, 'Sub-Agent für ' . $role, $subAgent);
        $this->logger->info('Sub-Agent erstellt', ['name' => $name]);
        return $subAgent;
    }

    /**
     * Erstellt einen Sub-Agenten als Subagent-Tool.
     */
    public function createSubAgentTool(
        string $name,
        string $role,
        string $model = 'mistral-large-latest',
        array $tools = []
    ): \Symfony\AI\Agent\Toolbox\Tool\Subagent {
        $this->logger->info('Erstelle SubAgent-Tool für Orchestrator', ['name' => $name, 'role' => $role]);
        $subAgent = new Agent(
            platform: $this->platform,
            model: $model,
            name: $name,
            inputProcessors: [new SystemPromptInputProcessor($this->generatePromptForRole($role))],
        );
        $subAgentTool = new \Symfony\AI\Agent\Toolbox\Tool\Subagent($subAgent);
        $this->registerToolDefinition($name, $role);
        $this->logger->info('SubAgent-Tool registriert', ['tool_name' => 'sub_agent_' . $name, 'agent_name' => $name]);
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
                'task' => ['type' => 'string', 'description' => 'Die Aufgabe, die der Sub-Agent ausführen soll'],
                'parameters' => ['type' => 'object', 'description' => 'Zusätzliche Parameter', 'additionalProperties' => true],
            ],
            'required' => ['task'],
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
        $toolDefinition->setCategory('sub_agent');
        $toolDefinition->setSchema([
            'type' => 'object',
            'properties' => [
                'task' => ['type' => 'string', 'description' => 'Die Aufgabe, die der Sub-Agent ausführen soll'],
                'parameters' => ['type' => 'object', 'description' => 'Zusätzliche Parameter', 'additionalProperties' => true],
            ],
            'required' => ['task'],
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
            'website_researcher' => 'Du bist ein spezialisierter Sub-Agent für Webseiten-Recherche. Deine Aufgabe: Durchsuche Webseiten nach Impressum, Kontakten, Geschäftszweck, Standort und Branche. Fasse die Informationen strukturiert zusammen.',
            'data_analyst' => 'Du bist ein Datenanalyst. Analysiere Daten und liefere Erkenntnisse.',
            'code_assistant' => 'Du bist ein Code-Assistent. Analysiere und generiere Code.',
            'document_processor' => 'Du bist ein Dokumenten-Prozessor. Verarbeite Dokumente.',
            'communication_manager' => 'Du bist der Communication Manager von EVIE. Verwalte E-Mails, Nachrichten, LinkedIn und andere Kommunikation.',
            'api_integration' => 'Du bist der API Integration Agent von EVIE. Binde externe APIs an, verwalte OAuth und Authentifizierung.',
            'project_manager' => 'Du bist der Project Manager von EVIE. Verwalte Aufgaben, Termine, Ressourcen und Projekte.',
            'finance_manager' => 'Du bist der Finance Manager von EVIE. Verwalte Buchhaltung, Rechnungen, Zahlungen.',
            'hr_manager' => 'Du bist der HR Manager von EVIE. Verwalte Mitarbeiter, Gehälter, Verträge.',
            'marketing_manager' => 'Du bist der Marketing Manager von EVIE. Verwalte Kampagnen, Social Media, Content.',
            'ceo_assistant' => 'Du bist der CEO Assistant von EVIE. Entwickle Strategien, treffe Entscheidungen, priorisiere Aufgaben.',
        ];
        return $rolePrompts[$role] ?? 'Du bist ein Sub-Agent. Führe Aufgaben aus.';
    }

    // Factory Methoden für spezifische Sub-Agenten
    public function createWebsiteResearchAgent(): AgentInterface { return $this->createSubAgent('website_researcher', 'website_researcher'); }
    public function createDataAnalysisAgent(): AgentInterface { return $this->createSubAgent('data_analyst', 'data_analyst'); }
    public function createCodeAssistantAgent(): AgentInterface { return $this->createSubAgent('code_assistant', 'code_assistant'); }
    public function createDocumentProcessorAgent(): AgentInterface { return $this->createSubAgent('document_processor', 'document_processor'); }
    public function createCommunicationManagerAgent(): AgentInterface { return $this->createSubAgent('communication_manager', 'communication_manager'); }
    public function createApiIntegrationAgent(): AgentInterface { return $this->createSubAgent('api_integration', 'api_integration'); }
    public function createProjectManagerAgent(): AgentInterface { return $this->createSubAgent('project_manager', 'project_manager'); }
    public function createFinanceManagerAgent(): AgentInterface { return $this->createSubAgent('finance_manager', 'finance_manager'); }
    public function createHrManagerAgent(): AgentInterface { return $this->createSubAgent('hr_manager', 'hr_manager'); }
    public function createMarketingManagerAgent(): AgentInterface { return $this->createSubAgent('marketing_manager', 'marketing_manager'); }
    public function createCeoAssistantAgent(): AgentInterface { return $this->createSubAgent('ceo_assistant', 'ceo_assistant'); }

    /**
     * Gibt alle verfügbaren Sub-Agenten zurück (statisch + dynamisch)
     */
    public function getAvailableSubAgents(): array
    {
        $dynamicSubAgents = $this->createAllFromDatabase();
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
        return array_merge($staticSubAgents, $dynamicSubAgents);
    }

    /**
     * Erstellt alle Sub-Agenten als Tools für den Orchestrator
     */
    public function createAllSubAgentTools(): array
    {
        $subAgentTools = [];
        foreach ($this->getAvailableSubAgents() as $name => $agent) {
            $subAgentTools[$name] = $this->createSubAgentTool($name, $name);
        }
        return $subAgentTools;
    }
}
