<?php
// src/AI/Skills/Tool/DynamicToolFactory.php

namespace App\AI\Skills\Tool;

use App\Entity\ToolDefinition;
use App\Repository\ToolDefinitionRepository;
use Symfony\AI\Agent\Tool\ToolInterface;
use Symfony\AI\Agent\Toolbox\ToolFactory\ToolFactoryInterface;
use Symfony\AI\Agent\Context\AgentContext;
use Symfony\Component\DependencyInjection\Attribute\AsTool;
use Psr\Log\LoggerInterface;

/**
 * Factory für dynamisch generierte Tools.
 * Wandelt ToolDefinition-Entitäten in ausführbare ToolInterface-Implementierungen um.
 */
#[AsTool(name: 'dynamic_tool_factory')]
final readonly class DynamicToolFactory implements ToolFactoryInterface
{
    public function __construct(
        private ToolDefinitionRepository $toolDefinitionRepo,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Erstellt ein Tool aus einer ToolDefinition.
     */
    public function getTool(): ToolInterface
    {
        return new class($this->toolDefinitionRepo, $this->logger) implements ToolInterface {
            private ToolDefinitionRepository $toolDefinitionRepo;
            private LoggerInterface $logger;

            public function __construct(ToolDefinitionRepository $toolDefinitionRepo, LoggerInterface $logger)
            {
                $this->toolDefinitionRepo = $toolDefinitionRepo;
                $this->logger = $logger;
            }

            public function getName(): string
            {
                return 'dynamic_tool_executor';
            }

            public function getDescription(): string
            {
                return 'Führt dynamisch generierte Tools basierend auf ToolDefinition-Entitäten aus.';
            }

            /**
             * Führt ein dynamisches Tool aus.
             * Erwartet den Tool-Namen als Parameter.
             */
            public function execute(array $parameters, AgentContext $context): string
            {
                // 1. Tool-Name aus Parametern extrahieren
                if (!isset($parameters['tool_name'])) {
                    throw new \InvalidArgumentException('Parameter "tool_name" ist erforderlich.');
                }

                $toolName = $parameters['tool_name'];
                
                // 2. ToolDefinition aus der Datenbank laden
                $toolDefinition = $this->toolDefinitionRepo->findOneBy([
                    'name' => $toolName,
                    'status' => 'approved', // Nur freigegebene Tools ausführen
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

                // 3. Tool ausführen
                return $this->executeTool($toolDefinition, $parameters, $context);
            }

            /**
             * Führt die eigentliche Tool-Logik aus.
             */
            private function executeTool(
                ToolDefinition $toolDefinition,
                array $parameters,
                AgentContext $context
            ): string {
                // 1. Validierung der Parameter gegen das Schema
                $this->validateParameters($toolDefinition, $parameters);

                // 2. Tool-spezifische Logik ausführen
                $result = $this->runToolLogic($toolDefinition, $parameters);

                // 3. Ergebnis zurückgeben
                return sprintf(
                    "Tool '%s' wurde erfolgreich ausgeführt.\n\nErgebnis: %s",
                    $toolDefinition->getName(),
                    is_array($result) ? json_encode($result, JSON_PRETTY_PRINT) : $result
                );
            }

            /**
             * Validiert die Parameter gegen das Tool-Schema.
             */
            private function validateParameters(ToolDefinition $toolDefinition, array $parameters): void
            {
                $schema = $toolDefinition->getSchema();
                
                if (!isset($schema['properties'])) {
                    return; // Keine Parameter zu validieren
                }

                $requiredParams = $schema['required'] ?? [];
                foreach ($requiredParams as $paramName) {
                    if (!isset($parameters[$paramName])) {
                        throw new \InvalidArgumentException(sprintf(
                            'Fehlender Required-Parameter: %s',
                            $paramName
                        ));
                    }
                }

                // Optional: Typ-Validierung
                foreach ($schema['properties'] as $paramName => $paramSchema) {
                    if (isset($parameters[$paramName])) {
                        $expectedType = $paramSchema['type'] ?? 'string';
                        $this->validateParameterType($paramName, $parameters[$paramName], $expectedType);
                    }
                }
            }

            /**
             * Validiert den Typ eines Parameters.
             */
            private function validateParameterType(string $paramName, mixed $value, string $expectedType): void
            {
                switch ($expectedType) {
                    case 'string':
                        if (!is_string($value)) {
                            throw new \InvalidArgumentException(sprintf(
                                'Parameter "%s" muss ein String sein.',
                                $paramName
                            ));
                        }
                        break;
                    case 'number':
                    case 'integer':
                        if (!is_int($value) && !is_float($value)) {
                            throw new \InvalidArgumentException(sprintf(
                                'Parameter "%s" muss eine Zahl sein.',
                                $paramName
                            ));
                        }
                        break;
                    case 'boolean':
                        if (!is_bool($value)) {
                            throw new \InvalidArgumentException(sprintf(
                                'Parameter "%s" muss ein Boolean sein.',
                                $paramName
                            ));
                        }
                        break;
                    case 'array':
                        if (!is_array($value)) {
                            throw new \InvalidArgumentException(sprintf(
                                'Parameter "%s" muss ein Array sein.',
                                $paramName
                            ));
                        }
                        break;
                }
            }

            /**
             * Führt die Tool-spezifische Logik aus.
             */
            private function runToolLogic(ToolDefinition $toolDefinition, array $parameters): mixed
            {
                $toolName = $toolDefinition->getName();
                
                // Hier könnte eine Switch-Case-Logik für bekannte Tool-Typen stehen
                // Oder ein GenericExecutor, der die Logik aus dem Schema ableitet
                
                // Beispiel für ein Website-Recherche-Tool
                if (str_contains($toolName, 'website') || str_contains($toolName, 'recherche')) {
                    return $this->executeWebsiteResearch($parameters);
                }

                // Beispiel für ein File-Tool
                if (str_contains($toolName, 'file') || str_contains($toolName, 'datei')) {
                    return $this->executeFileTool($parameters);
                }

                // Standard-Fallback
                return sprintf(
                    "Tool '%s' wurde mit Parametern ausgeführt: %s",
                    $toolName,
                    json_encode($parameters)
                );
            }

            /**
             * Beispiel: Website-Recherche ausführen.
             */
            private function executeWebsiteResearch(array $parameters): array
            {
                $url = $parameters['url'] ?? 'https://example.com';
                
                // Mock-Ergebnis für Demonstration
                return [
                    'url' => $url,
                    'title' => 'Beispiel-Website',
                    'impressum' => 'Impressum gefunden',
                    'kontakte' => ['email@beispiel.de'],
                    'branche' => 'IT-Dienstleistungen',
                    'standort' => 'Berlin, Deutschland',
                ];
            }

            /**
             * Beispiel: File-Tool ausführen.
             */
            private function executeFileTool(array $parameters): array
            {
                $filePath = $parameters['file_path'] ?? '';
                
                // Mock-Ergebnis
                return [
                    'file' => $filePath,
                    'status' => 'verarbeitet',
                    'size' => filesize($filePath) ?? 0,
                ];
            }
        };
    }
}
