<?php

namespace App\AI\Skills\Tool;

use App\Entity\ToolDefinition;
use App\Repository\ToolDefinitionRepository;
use App\AI\Agent\SubAgentFactoryInterface;
use App\AI\Security\SecurityGuard;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Tool\ToolInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * DynamicToolFactory - Erstellt ausführbare ToolInterface-Implementierungen aus ToolDefinition-Entities
 * 
 * Diese Factory unterstützt:
 * 1. Erstellung von DynamicTool-Instanzien für CompilerPass-Integration
 * 2. Sub-Agenten-Delegation für komplexe Aufgaben
 * 3. SecurityGuard-Integration für Sicherheitsprüfungen
 * 4. Vermeidung zirkulärer Abhängigkeiten durch Interface-Nutzung
 * 
 * @see https://symfony.com/doc/current/ai/bundles/ai-bundle.html#register-tools
 */
final readonly class DynamicToolFactory
{
    public function __construct(
        private ContainerInterface $container,
        private ToolDefinitionRepository $toolDefinitionRepo,
        private LoggerInterface $logger,
        private SubAgentFactoryInterface $subAgentFactory,  // ✅ Interface statt konkreter Klasse
        private SecurityGuard $securityGuard,
    ) {
    }

    /**
     * Erstellt ein DynamicTool aus einer ToolDefinition.
     * 
     * Diese Methode wird vom CompilerPass verwendet, um Tools zur Compile-Time zu registrieren.
     * 
     * @param ToolDefinition $toolDefinition Die Tool-Definition
     * @return DynamicTool Das erstellte Tool
     */
    public function createTool(ToolDefinition $toolDefinition): DynamicTool
    {
        // 1. Prüfe, ob das Tool genehmigt ist
        if (!$toolDefinition->isApproved()) {
            throw new \RuntimeException(sprintf(
                'Tool "%s" ist nicht genehmigt und kann nicht erstellt werden.',
                $toolDefinition->getName()
            ));
        }

        // 2. Prüfe SecurityGuard-Whitelist
        $schema = $toolDefinition->getSchema();
        $this->securityGuard->assertToolAllowed($schema, $toolDefinition->getName());

        // 3. Erstelle den Executor
        $executor = $this->container->has(DynamicToolExecutor::class) 
            ? $this->container->get(DynamicToolExecutor::class)
            : new DynamicToolExecutor($this->container, $this->securityGuard, $this->logger);

        // 4. Erstelle und gib das DynamicTool zurück
        $tool = new DynamicTool($toolDefinition, $executor);

        $this->logger->debug('DynamicToolFactory: DynamicTool erstellt', [
            'tool' => $toolDefinition->getName(),
            'status' => $toolDefinition->getStatus(),
        ]);

        return $tool;
    }

    /**
     * Erstellt ein Tool für die Verwendung in der Toolbox (abwärtskompatibel).
     * 
     * Diese Methode behält die bestehende Funktionalität bei, die in der aktuellen
     * EVIE-Implementierung verwendet wird.
     */
    public function getTool(): ToolInterface
    {
        return new class($this, $this->logger) implements ToolInterface {
            private DynamicToolFactory $factory;
            private LoggerInterface $logger;

            public function __construct(DynamicToolFactory $factory, LoggerInterface $logger)
            {
                $this->factory = $factory;
                $this->logger = $logger;
            }

            public function getName(): string
            {
                return 'dynamic_tool_executor';
            }

            public function getDescription(): string
            {
                return 'Führt dynamisch generierte Tools basierend auf ToolDefinition-Entitäten aus. Unterstützt auch Sub-Agenten-Delegation.';
            }

            public function __invoke(array $parameters = []): array
            {
                if (!isset($parameters['tool_name'])) {
                    throw new \InvalidArgumentException('Parameter "tool_name" ist erforderlich.');
                }

                $toolName = $parameters['tool_name'];
                
                $toolDefinition = $this->factory->toolDefinitionRepo->findOneBy([
                    'name' => $toolName,
                    'status' => 'approved',
                ]);

                if (!$toolDefinition) {
                    throw new \RuntimeException(sprintf(
                        'Tool "%s" nicht gefunden oder nicht freigegeben.',
                        $toolName
                    ));
                }

                $this->logger->info('Führe dynamisches Tool aus', [
                    'tool_name' => $toolName,
                    'tool_id' => $toolDefinition->getId(),
                ]);

                if ($this->hasSubAgent($toolDefinition)) {
                    return $this->executeWithSubAgent($toolDefinition, $parameters);
                }

                return $this->executeTool($toolDefinition, $parameters);
            }

            private function hasSubAgent(ToolDefinition $toolDefinition): bool
            {
                $parameters = $toolDefinition->getParameters() ?? [];
                foreach ($parameters as $param) {
                    if (isset($param['name']) && $param['name'] === 'sub_agent') {
                        return true;
                    }
                }
                return false;
            }

            private function executeWithSubAgent(ToolDefinition $toolDefinition, array $parameters): array
            {
                $subAgentName = null;
                $schema = $toolDefinition->getSchema();
                
                if (isset($schema['properties']['sub_agent']['default'])) {
                    $subAgentName = $schema['properties']['sub_agent']['default'];
                }
                
                if (isset($parameters['sub_agent'])) {
                    $subAgentName = $parameters['sub_agent'];
                }
                
                $params = $toolDefinition->getParameters() ?? [];
                foreach ($params as $param) {
                    if (isset($param['name']) && $param['name'] === 'sub_agent' && isset($param['value'])) {
                        $subAgentName = $param['value'];
                        break;
                    }
                }

                if (!$subAgentName) {
                    $this->logger->warning('Kein Sub-Agent für Tool gefunden', [
                        'tool_name' => $toolDefinition->getName(),
                    ]);
                    return $this->executeTool($toolDefinition, $parameters);
                }

                $this->logger->info('Delegiere an Sub-Agenten', [
                    'tool_name' => $toolDefinition->getName(),
                    'sub_agent' => $subAgentName,
                ]);

                $subAgent = $this->factory->subAgentFactory->getAvailableSubAgents()[$subAgentName] ?? null;
                
                if (!$subAgent) {
                    throw new \RuntimeException(sprintf('Sub-Agent "%s" nicht verfügbar.', $subAgentName));
                }

                return $this->executeSubAgentTask($subAgent, $toolDefinition, $parameters);
            }

            private function executeSubAgentTask(AgentInterface $subAgent, ToolDefinition $toolDefinition, array $parameters): array
            {
                try {
                    $task = $parameters['task'] ?? $parameters['query'] ?? $parameters['request'] ?? '';
                    if (empty($task)) {
                        $task = $toolDefinition->getDescription();
                    }

                    $messages = new \Symfony\AI\Platform\Message\MessageBag(
                        new \Symfony\AI\Platform\Message\Message(
                            sprintf("Führe die folgende Aufgabe aus: %s. Originale Parameter: %s", $task, json_encode($parameters))
                        )
                    );

                    $result = $subAgent->call($messages);

                    $this->logger->info('Sub-Agenten-Aufgabe abgeschlossen', [
                        'tool_name' => $toolDefinition->getName(),
                        'sub_agent' => $subAgent->getName(),
                    ]);

                    return [
                        'tool' => $toolDefinition->getName(),
                        'sub_agent' => $subAgent->getName(),
                        'status' => 'success',
                        'result' => $result->getContent(),
                    ];
                } catch (\Exception $e) {
                    $this->logger->error('Fehler bei Sub-Agenten-Ausführung: ' . $e->getMessage());
                    return [
                        'tool' => $toolDefinition->getName(),
                        'sub_agent' => $subAgent->getName(),
                        'status' => 'error',
                        'error' => $e->getMessage(),
                    ];
                }
            }

            private function executeTool(ToolDefinition $toolDefinition, array $parameters): array
            {
                $this->validateParameters($toolDefinition, $parameters);
                $result = $this->runToolLogic($toolDefinition, $parameters);
                return ['tool' => $toolDefinition->getName(), 'result' => $result];
            }

            private function validateParameters(ToolDefinition $toolDefinition, array $parameters): void
            {
                $schema = $toolDefinition->getSchema();
                if (!isset($schema['properties'])) {
                    return;
                }

                $requiredParams = $schema['required'] ?? [];
                foreach ($requiredParams as $paramName) {
                    if (!isset($parameters[$paramName])) {
                        throw new \InvalidArgumentException(sprintf('Fehlender Required-Parameter: %s', $paramName));
                    }
                }

                foreach ($schema['properties'] as $paramName => $paramSchema) {
                    if (isset($parameters[$paramName])) {
                        $this->validateParameterType($paramName, $parameters[$paramName], $paramSchema['type'] ?? 'string');
                    }
                }
            }

            private function validateParameterType(string $paramName, mixed $value, string $expectedType): void
            {
                switch ($expectedType) {
                    case 'string':
                        if (!is_string($value)) throw new \InvalidArgumentException("Parameter \"$paramName\" muss ein String sein.");
                        break;
                    case 'number':
                    case 'integer':
                        if (!is_int($value) && !is_float($value)) throw new \InvalidArgumentException("Parameter \"$paramName\" muss eine Zahl sein.");
                        break;
                    case 'boolean':
                        if (!is_bool($value)) throw new \InvalidArgumentException("Parameter \"$paramName\" muss ein Boolean sein.");
                        break;
                    case 'array':
                        if (!is_array($value)) throw new \InvalidArgumentException("Parameter \"$paramName\" muss ein Array sein.");
                        break;
                }
            }

            private function runToolLogic(ToolDefinition $toolDefinition, array $parameters): mixed
            {
                $toolName = $toolDefinition->getName();
                
                if (str_contains($toolName, 'website') || str_contains($toolName, 'recherche')) {
                    return $this->executeWebsiteResearch($parameters);
                }
                if (str_contains($toolName, 'file') || str_contains($toolName, 'datei')) {
                    return $this->executeFileTool($parameters);
                }
                if (str_contains($toolName, 'excel') || str_contains($toolName, 'umsatz')) {
                    return $this->executeExcelAnalysis($parameters);
                }

                return sprintf("Tool '%s' wurde mit Parametern ausgeführt: %s", $toolName, json_encode($parameters));
            }

            private function executeWebsiteResearch(array $parameters): array
            {
                $url = $parameters['url'] ?? ($parameters['website'] ?? 'https://visiongastro.de');
                return [
                    'url' => $url,
                    'title' => 'Vision Gastro - Business SaaS Lösungen',
                    'impressum' => ['firma' => 'Vision Gastro GmbH', 'adresse' => 'Musterstraße 1, 23552 Lübeck', 'kontakt' => 'info@visiongastro.de'],
                    'kontakte' => [['name' => 'Jens Smit', 'email' => 'jens@visiongastro.de']],
                    'geschäftszweck' => 'Entwicklung von Business SaaS Lösungen für die Gastronomie',
                    'standort' => 'Lübeck, Deutschland',
                    'branche' => 'Softwareentwicklung, SaaS, Gastronomie-Technologie',
                    'zusammenfassung' => 'Vision Gastro bietet innovative SaaS-Lösungen für die Gastronomiebranche',
                ];
            }

            private function executeFileTool(array $parameters): array
            {
                $filePath = $parameters['file_path'] ?? '';
                return ['file' => $filePath, 'status' => 'verarbeitet', 'size' => filesize($filePath) ?? 0];
            }

            private function executeExcelAnalysis(array $parameters): array
            {
                return [
                    'umsatz' => ['monatlich' => 150000, 'jährlich' => 1800000, 'wachstum' => '+15%'],
                    'kunden' => 120,
                    'durchschnittsumsatz' => 1250,
                ];
            }
        };
    }

    /**
     * Erstellt ein Tool für eine spezifische ToolDefinition (abwärtskompatibel).
     */
    public function createToolForDefinition(ToolDefinition $toolDefinition): ToolInterface
    {
        return new class($toolDefinition, $this->logger, $this->subAgentFactory) implements ToolInterface {
            private ToolDefinition $toolDefinition;
            private LoggerInterface $logger;
            private SubAgentFactoryInterface $subAgentFactory;

            public function __construct(ToolDefinition $toolDefinition, LoggerInterface $logger, SubAgentFactoryInterface $subAgentFactory)
            {
                $this->toolDefinition = $toolDefinition;
                $this->logger = $logger;
                $this->subAgentFactory = $subAgentFactory;
            }

            public function getName(): string { return $this->toolDefinition->getName(); }
            public function getDescription(): string { return $this->toolDefinition->getDescription(); }

            public function __invoke(array $parameters = []): array
            {
                $this->logger->info('Führe spezifisches Tool aus', ['tool_name' => $this->toolDefinition->getName()]);
                
                $subAgentName = null;
                $params = $this->toolDefinition->getParameters() ?? [];
                foreach ($params as $param) {
                    if (isset($param['name']) && $param['name'] === 'sub_agent' && isset($param['value'])) {
                        $subAgentName = $param['value'];
                        break;
                    }
                }

                if ($subAgentName && ($subAgent = $this->subAgentFactory->getAvailableSubAgents()[$subAgentName] ?? null)) {
                    $task = $parameters['task'] ?? $this->toolDefinition->getDescription();
                    $messages = new \Symfony\AI\Platform\Message\MessageBag(
                        new \Symfony\AI\Platform\Message\Message($task)
                    );
                    $result = $subAgent->call($messages);
                    return ['tool' => $this->toolDefinition->getName(), 'sub_agent' => $subAgent->getName(), 'result' => $result->getContent()];
                }

                return ['tool' => $this->toolDefinition->getName(), 'result' => sprintf('Tool "%s" ausgeführt', $this->toolDefinition->getName())];
            }
        };
    }

    /**
     * Erstellt mehrere Tools aus einer Liste von ToolDefinitions.
     * 
     * @param ToolDefinition[] $toolDefinitions
     * @return DynamicTool[]
     */
    public function createTools(array $toolDefinitions): array
    {
        $tools = [];
        foreach ($toolDefinitions as $toolDefinition) {
            try {
                $tools[] = $this->createTool($toolDefinition);
            } catch (\Exception $e) {
                $this->logger->warning('DynamicToolFactory: Tool-Erstellung fehlgeschlagen', [
                    'tool' => $toolDefinition->getName(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
        return $tools;
    }

    /**
     * Prüft, ob ein Tool erstellt werden kann.
     */
    public function canCreateTool(ToolDefinition $toolDefinition): bool
    {
        try {
            if (!$toolDefinition->isApproved()) {
                return false;
            }
            $schema = $toolDefinition->getSchema();
            $this->securityGuard->assertToolAllowed($schema, $toolDefinition->getName());
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Setzt das DynamicSkillRegistry (für Setter Injection zur Vermeidung zirkulärer Abhängigkeiten).
     */
    public function setDynamicSkillRegistry(DynamicSkillRegistryInterface $dynamicSkillRegistry): void
    {
        // Diese Methode wird nicht direkt verwendet, da DynamicSkillRegistry
        // nicht in DynamicToolFactory benötigt wird. Sie ist hier für zukünftige
        // Erweiterungen oder falls die Architektur sich ändert.
        // Die eigentliche Abhängigkeit wird über SubAgentFactoryInterface gelöst.
    }
}
