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

    // ... [REST DER DATEI BLEIBT UNVERÄNDERT - ich kürze für die Antwort] ...

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
        ];
        return array_merge($staticSubAgents, $dynamicSubAgents);
    }
}
