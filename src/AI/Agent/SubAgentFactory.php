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
use Symfony\AI\Agent\Toolbox\AgentProcessor;
use Symfony\AI\Agent\Toolbox\ToolFactory\MemoryToolFactory;
use Symfony\AI\Agent\Toolbox\Toolbox;
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
class SubAgentFactory implements SubAgentFactoryInterface
{
    private PlatformInterface $platform;
    private ToolDefinitionRepository $toolDefinitionRepo;
    
    
    private LoggerInterface $logger;
    private ContainerInterface $container;
    private SubAgentDefinitionRepository $subAgentDefinitionRepo;
    private ParameterBagInterface $params;
    private bool $enforceResearchCapability = false;

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

        // 1. Nur echte, als Service registrierte AgentInterface-Implementierungen
        // werden aus dem Container geholt. class_name ist KEINE Symfony-DI-
        // Service-ID fuer konkrete Basisklassen (Fix fuer "non-existent service
        // Symfony\AI\Agent\Agent" aus dem dev-tail-Log): ist die Klasse nicht
        // als Service registriert, faellt die Factory auf die Konfiguration
        // zurueck, statt container->get() mit einer Klasse zu fuettern.
        if ($className !== null && $this->container->has($className)) {
            $subAgent = $this->container->get($className);
            if ($subAgent instanceof AgentInterface) {
                $this->registerAsTool($name, $definition->getDescription(), $subAgent);
                return $subAgent;
            }
        }

        // 2. Bevorzugung: Unter ai.agent.<name> konfigurierte Bundle-Agenten
        // (config/packages/ai.yaml) bringen Toolbox, Prompt und Tool-Calling
        // nativ mit; nur wenn kein Bundle-Agent existiert, wird generisch
        // gebaut.
        $bundleAgent = $this->resolveBundleAgent($name);
        if ($bundleAgent !== null) {
            $this->registerAsTool($name, $definition->getDescription(), $bundleAgent);
            $this->logger->info('Sub-Agent aus Bundle-Konfiguration verwendet', ['name' => $name]);
            return $bundleAgent;
        }
        // 3. Falls nicht, erstelle einen generischen Agenten mit der Konfiguration
        $model = $configuration['model'] ?? 'mistral-small-latest';
        $role = $configuration['role'] ?? $name;

        $subAgent = $this->buildAgent(
            $name,
            $model,
            $this->generatePromptForRole($role),
            $this->resolveToolsForRole($role),
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
        // Sub-Agent-Tools haben keine ToolCategory-Entity; null ist typkonform
        // (setCategory erwartet ?ToolCategory, keinen String).
        $toolDefinition->setCategory(null);
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
        // createByName ist der Ausfuehrungspfad der Pipeline (Phase 5):
        // Hier muss ein website_researcher ohne Recherche-Tool fehlschlagen,
        // damit die Pipeline keinen halluzinierten Businessplan erzeugt.
        // Rein konstruierende Pfade (getAvailableSubAgents etc.) bleiben
        // tolerant, damit Seiten wie /subagents auch ohne API-Key laden.
        $this->enforceResearchCapability = true;
        try {
            $definition = $this->subAgentDefinitionRepo->findOneByName($name);
            if ($definition !== null) {
                return $this->createFromDefinition($definition);
            }
            return $this->createFromStaticConfig($name);
        } finally {
            $this->enforceResearchCapability = false;
        }
    }

    /**
     * Erstellt einen Sub-Agenten aus statischer Konfiguration.
     */
    private function createFromStaticConfig(string $name): AgentInterface
    {
        $this->logger->info('Erstelle Sub-Agenten aus statischer Konfiguration', ['name' => $name]);
        $bundleAgent = $this->resolveBundleAgent($name);
        if ($bundleAgent !== null) {
            $this->registerAsTool($name, 'Sub-Agent für ' . $name, $bundleAgent);
            return $bundleAgent;
        }
        $subAgent = $this->buildAgent(
            $name,
            'mistral-small-latest',
            $this->generatePromptForRole($name),
            $this->resolveToolsForRole($name),
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
        string $model = 'mistral-small-latest',
        array $tools = []
    ): AgentInterface {
        $this->logger->info('Erstelle neuen Sub-Agenten', ['name' => $name, 'role' => $role]);
        $tools = $tools !== [] ? $tools : $this->resolveToolsForRole($role);
        $subAgent = $this->buildAgent($name, $model, $this->generatePromptForRole($role), $tools);
        $this->registerAsTool($name, 'Sub-Agent für ' . $role, $subAgent);
        $this->logger->info('Sub-Agent erstellt', ['name' => $name, 'tools' => count($tools)]);
        return $subAgent;
    }

    /**
     * Erstellt einen Sub-Agenten als Subagent-Tool.
     */
    public function createSubAgentTool(
        string $name,
        string $role,
        string $model = 'mistral-small-latest',
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
        $toolName = 'sub_agent_' . $name;
        if ($this->toolDefinitionRepo->findOneBy(['name' => $toolName]) !== null) {
            return;
        }

        $toolDefinition = new ToolDefinition();
        $toolDefinition->setName($toolName);
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
    }

    /**
     * Registriert den Sub-Agenten als Tool für den Orchestrator.
     */
    private function registerAsTool(string $name, string $description, AgentInterface $agent): void
    {
        $toolName = 'sub_agent_' . $name;
        if ($this->toolDefinitionRepo->findOneBy(['name' => $toolName]) !== null) {
            return;
        }

        $toolDefinition = new ToolDefinition();
        $toolDefinition->setName($toolName);
        $toolDefinition->setDescription($description);
        $toolDefinition->setStatus('approved');
        $toolDefinition->setCategory(null);
        $toolDefinition->setSchema([
            'type' => 'object',
            'properties' => [
                'task' => ['type' => 'string', 'description' => 'Die Aufgabe, die der Sub-Agent ausführen soll'],
                'parameters' => ['type' => 'object', 'description' => 'Zusätzliche Parameter', 'additionalProperties' => true],
            ],
            'required' => ['task'],
        ]);
        $this->toolDefinitionRepo->save($toolDefinition, true);
    }

    /**
     * Bevorzugt den unter ai.agent.<name> konfigurierten Bundle-Agenten
     * (config/packages/ai.yaml). Bundle-Agenten bringen ihre Toolbox, ihre
     * Prozessoren und Tool-Calling nativ mit (AiBundle::processAgentConfig);
     * lokal gebaute new-Agent-Instanzen ohne AgentProcessor koennen dagegen
     * keine Tools ausfuehren. Gibt null zurueck, wenn kein Bundle-Agent
     * existiert oder der Container ihn nicht als AgentInterface liefert.
     */
    private function resolveBundleAgent(string $name): ?AgentInterface
    {
        $serviceId = 'ai.agent.' . $name;
        try {
            if (!$this->container->has($serviceId)) {
                return null;
            }
            $agent = $this->container->get($serviceId);
        } catch (\Throwable $e) {
            $this->logger->warning('Bundle-Agent nicht verfuegbar, faellt auf generischen Bau zurueck', [
                'service_id' => $serviceId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
        if (!$agent instanceof AgentInterface) {
            return null;
        }
        return $agent;
    }

    /**
     * Baut einen Agenten mit nativem Tool-Calling (Blueprint: Symfony-AI-
     * Erweiterungspunkte, keine Eigenbau-Bridge). Ohne AgentProcessor +
     * Toolbox kann ein Agent keine Tools ausfuehren; der fruehere Bau
     * ignorierte den $tools-Parameter vollstaendig, sodass z.B. der
     * website_researcher nur aus Modellwissen (Halluzinationsrisiko)
     * statt ueber Tavily/MCP recherchieren konnte. Die Toolbox erhaelt die
     * Tool-Instanzen; MemoryToolFactory haelt Name/Beschreibung/Methode der
     * nicht-#[AsTool]-attribuierten Tools, SystemPromptInputProcessor haengt
     * die Tool-Schemata an den Prompt.
     *
     * @param list<object> $tools
     */
    private function buildAgent(string $name, string $model, string $prompt, array $tools): Agent
    {
        $inputProcessors = [];
        $outputProcessors = [];
        if ($tools !== []) {
            $memoryFactory = new MemoryToolFactory();
            foreach ($tools as $tool) {
                $memoryFactory->addTool($tool, $this->toolNameOf($tool), $this->toolDescriptionOf($tool));
            }
            $toolbox = new Toolbox($tools, $memoryFactory);
            $toolProcessor = new AgentProcessor($toolbox);
            $inputProcessors[] = $toolProcessor;
            $outputProcessors[] = $toolProcessor;
            $inputProcessors[] = new SystemPromptInputProcessor($prompt, $toolbox);
        } else {
            $inputProcessors[] = new SystemPromptInputProcessor($prompt);
        }
        return new Agent(
            platform: $this->platform,
            model: $model,
            name: $name,
            inputProcessors: $inputProcessors,
            outputProcessors: $outputProcessors,
        );
    }

    /**
     * Liefert die fuer eine Rolle passenden Tool-Instanzen aus dem Service-
     * Container (keine Konstruktor-Injection einzelner Tools, Blueprint 4.D).
     * Werkzeuge, die im Container fehlen (z.B. ohne konfigurierten MCP-Server
     * oder API-Key), werden uebersprungen; der Agent startet dann mit dem
     * verbleibenden Tool-Set statt komplett zu scheitern.
     *
     * Ausnahme: Der website_researcher benoetigt zwingend mindestens ein
     * Recherche-Tool (Tavily oder MCP). Ohne solches Tool wuerde der Agent
     * ausschliesslich aus Modellwissen antworten (Halluzinationsrisiko,
     * Log-Fall visiongastro-Businessplan). In diesem Fall wird kein
     * degenerierter Agent erzeugt, sondern eindeutig fehlschlagen gelassen:
     * die Pipeline bricht mit klarem Fehler ab, statt stillschweigend
     * erfundene Inhalte zu liefern (Blueprint: keine Halluzinationen).
     *
     * @return list<object>
     */
    private function resolveToolsForRole(string $role): array
    {
        $toolIdsByRole = [
            'website_researcher' => [
                'App\AI\Skills\Tool\FileReadTool',
                'Symfony\AI\Agent\Bridge\Tavily\Tavily',
                'App\Mcp\Toolbox\McpToolExecutor',
            ],
            'data_analyst' => [
                'App\AI\Skills\Tool\DataAnalyzerTool',
                'App\AI\Skills\Tool\ExcelParserTool',
            ],
            'document_processor' => [
                'App\AI\Skills\Tool\FileReadTool',
                'App\AI\Skills\Tool\ExcelParserTool',
            ],
            'code_assistant' => [
                'App\AI\Skills\Tool\FileReadTool',
            ],
            'communication_manager' => [
                'App\AI\Skills\Tool\EmailTool',
                'App\AI\Skills\Tool\LinkedInTool',
            ],
            'api_integration' => [
                'App\AI\Skills\Tool\OAuthTool',
                'App\AI\Skills\Tool\RestApiTool',
            ],
        ];
        $tools = [];
        foreach ($toolIdsByRole[$role] ?? [] as $toolId) {
            try {
                if (!$this->container->has($toolId)) {
                    continue;
                }
                $tool = $this->container->get($toolId);
            } catch (\Throwable $e) {
                $this->logger->warning('Tool fuer Sub-Agent nicht verfuegbar, uebersprungen', [
                    'role' => $role,
                    'tool' => $toolId,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }
            if (is_object($tool)) {
                $tools[] = $tool;
            }
        }
        if ($role === 'website_researcher' && !$this->hasResearchCapability($tools)) {
            // Enforcement nur im Ausführungspfad (createByName), nicht beim
            // reinen Konstruieren fuer Listen/Tool-Registrierung: Diese bauen
            // z.B. /subagents alle Agenten, auch ohne geplanten Abruf.
            if ($this->enforceResearchCapability) {
                throw new \App\AI\Pipeline\Exception\UngroundedResearchException(
                    'Der website_researcher hat kein verfuegbares Recherche-Tool '
                    . '(Tavily-Tool ohne TAVILY_API_KEY und/oder McpToolExecutor '
                    . 'ohne konfigurierten MCP-Server). Ohne Abruf-Tool wuerde der '
                    . 'Agent aus Modellwissen hallucinieren. Bitte TAVILY_API_KEY '
                    . 'setzen oder einen MCP-Server fuer Web-Recherche konfigurieren '
                    . 'und die Ziel-Domain in der Outbound-Allowlist freigeben.'
                );
            }
            $this->logger->warning('website_researcher ohne Recherche-Tool konstruiert (kein Enforcement in diesem Pfad)', [
                'role' => $role,
            ]);
        }
        return $tools;
    }

    /**
     * Prueft, ob in der Tool-Liste mindestens ein Web-Recherche-Tool
     * vorhanden ist (Tavily-Bridge oder MCP-Tool-Executor). Lokale Tools
     * wie FileReadTool zaehlen nicht als Recherche-Faehigkeit.
     *
     * @param list<object> $tools
     */
    private function hasResearchCapability(array $tools): bool
    {
        foreach ($tools as $tool) {
            if ($tool instanceof \Symfony\AI\Agent\Bridge\Tavily\Tavily
                || $tool instanceof \App\Mcp\Toolbox\McpToolExecutor
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Ermittelt den Tool-Namen: ToolInterface::getName() oder natives
     * #[AsTool]-Attribut (ReflectionToolFactory kann Letzteres selbst; fuer
     * die MemoryToolFactory-Registrierung benoetigen wir den Namen explizit).
     */
    private function toolNameOf(object $tool): string
    {
        if ($tool instanceof \App\AI\Skills\Tool\ToolInterface) {
            return $tool->getName();
        }
        $attributes = (new \ReflectionClass($tool))->getAttributes(\Symfony\AI\Agent\Toolbox\Attribute\AsTool::class);
        foreach ($attributes as $attribute) {
            return $attribute->newInstance()->name;
        }
        throw new \LogicException(sprintf('Tool %s hat weder #[AsTool] noch ToolInterface::getName().', $tool::class));
    }

    /**
     * Ermittelt die Tool-Beschreibung analog toolNameOf().
     */
    private function toolDescriptionOf(object $tool): string
    {
        if ($tool instanceof \App\AI\Skills\Tool\ToolInterface) {
            return $tool->getDescription();
        }
        $attributes = (new \ReflectionClass($tool))->getAttributes(\Symfony\AI\Agent\Toolbox\Attribute\AsTool::class);
        foreach ($attributes as $attribute) {
            return $attribute->newInstance()->description ?? '';
        }
        throw new \LogicException(sprintf('Tool %s hat kein #[AsTool]-Attribut.', $tool::class));
    }

    private function generatePromptForRole(string $role): string
    {
        $rolePrompts = [
            'website_researcher' => 'Du bist ein spezialisierter Sub-Agent für Webseiten-Recherche. Deine Aufgabe: Durchsuche Webseiten nach Impressum, Kontakten, Geschäftszweck, Standort und Branche. Fasse die Informationen strukturiert zusammen.',
            'data_analyst' => 'Du bist ein Datenanalyst. Analysiere die uebergebenen Daten und Vorergebnisse strukturiert und liefere belastbare Erkenntnisse mit klarer Gliederung (z.B. Marktuebersicht, Wettbewerbsvergleich als Tabelle, Stärken-Schwaechen, Chancen-Risiken, Finanzzahlen). Nutze ausschliesslich die uebergebenen Informationen - erfinde KEINE Zahlen. Kennzeichne fehlende Informationen explizit als solche, damit der content_synthesizer weiss, welche Angaben fehlen.',
            'content_synthesizer' => 'Du bist der Content-Synthesizer von EVIE. Deine Aufgabe: Verdichte beliebig viele Vorergebnisse (Recherche, Analysen) zu einem ausformulierten Fachtext gemäss der dir uebergebenen Aufgabenspezifikation (Abschnittsstruktur, Mindesttiefe, Sprache, Zielpublikum). Nutze AUSSCHLIESSLICH die uebergebenen Vorergebnisse - erfinde keine Fakten, Zahlen oder Unternehmen. Angaben, die in den Vorergebnissen nicht vorhanden sind, kennzeichne als "nicht ersichtlich" statt sie zu ergaenzen. Jeder Abschnitt erhaelt ausformulierten Fliesstext (keine Stichpunkte als Ersatz, keine Platzhalter wie [TBD]). Antworte mit reinem Markdown OHNE umgebende Code-Bloecke (keine ```markdown-Fences) und OHNE Meta-Ueberschrift wie "Business Plan Content" - beginne direkt mit der ersten inhaltlichen Ueberschrift des Dokuments.',
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
    public function createContentSynthesizerAgent(): AgentInterface { return $this->createSubAgent('content_synthesizer', 'content_synthesizer', 'mistral-small-latest'); }
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
            'content_synthesizer' => $this->createContentSynthesizerAgent(),
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
}
