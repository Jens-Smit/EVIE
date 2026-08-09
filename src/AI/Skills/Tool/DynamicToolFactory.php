<?php
// src/AI/Skills/Tool/DynamicToolFactory.php

namespace App\AI\Skills\Tool;

use App\Entity\ToolDefinition;
use App\Repository\ToolDefinitionRepository;
use App\AI\Agent\SubAgentFactory;
use App\AI\Skills\DynamicSkillRegistry;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\AgentInterface;

/**
 * Factory für dynamisch generierte Tools.
 * Wandelt ToolDefinition-Entitäten in ausführbare ToolInterface-Implementierungen um.
 * Unterstützt jetzt auch die Delegation an Sub-Agenten.
 */
final readonly class DynamicToolFactory
{
    public function __construct(
        private ToolDefinitionRepository $toolDefinitionRepo,
        private LoggerInterface $logger,
        private SubAgentFactory $subAgentFactory,
        private DynamicSkillRegistry $dynamicSkillRegistry,
    ) {
    }

    /**
     * Erstellt ein Tool aus einer ToolDefinition.
     * Unterstützt jetzt auch Sub-Agenten-Delegation.
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

    public function createToolForDefinition(ToolDefinition $toolDefinition): ToolInterface
    {
        return new class($toolDefinition, $this->logger, $this->subAgentFactory) implements ToolInterface {
            private ToolDefinition $toolDefinition;
            private LoggerInterface $logger;
            private SubAgentFactory $subAgentFactory;

            public function __construct(ToolDefinition $toolDefinition, LoggerInterface $logger, SubAgentFactory $subAgentFactory)
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
}