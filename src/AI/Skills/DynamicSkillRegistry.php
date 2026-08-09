<?php

namespace App\AI\Skills;

use App\Entity\ToolDefinition;
use App\Repository\ToolDefinitionRepository;

/**
 * DynamicSkillRegistry verwaltet die verfügbaren Tools.
 * Er lädt freigegebene Tools aus der Datenbank und stellt Metadaten für den DynamicToolDispatcher bereit.
 * 
 * Verbesserungen:
 * - Automatisches Nachladen von Tools nach Genehmigung
 * - Event-basierte Aktualisierung
 * - Bessere Fehlerbehandlung
 */
class DynamicSkillRegistry
{
    private ToolDefinitionRepository $toolDefinitionRepo;
    private array $tools = [];
    private bool $initialized = false;

    public function __construct(ToolDefinitionRepository $toolDefinitionRepo)
    {
        $this->toolDefinitionRepo = $toolDefinitionRepo;
    }

    /**
     * Initialisiert das Registry und lädt alle freigegebenen Tools.
     */
    public function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        $this->loadTools();
        $this->initialized = true;
    }

    /**
     * Lädt alle freigegebenen Tools aus der Datenbank.
     */
    public function loadTools(): void
    {
        $toolDefinitions = $this->toolDefinitionRepo->findBy(['status' => 'approved']);
        
        foreach ($toolDefinitions as $toolDefinition) {
            $this->tools[$toolDefinition->getName()] = $toolDefinition;
        }

        // Log laden der Tools
        // Note: Logger wird hier nicht injiziert, um Abhängigkeiten zu vermeiden
        // Das Logging sollte über den Service erfolgen, der dieses Registry nutzt
    }

    /**
     * Lädt das Registry neu (z. B. nach Genehmigung eines neuen Tools).
     */
    public function reload(): void
    {
        $this->tools = [];
        $this->initialized = false;
        $this->initialize();
    }

    /**
     * Gibt alle verfügbaren Tools als Metadaten zurück.
     * @return array<string, array{name: string, description: string, schema: array, status: string}>
     */
    public function getAvailableTools(): array
    {
        if (!$this->initialized) {
            $this->initialize();
        }

        $availableTools = [];
        foreach ($this->tools as $name => $toolDefinition) {
            $availableTools[$name] = [
                'name' => $toolDefinition->getName(),
                'description' => $toolDefinition->getDescription(),
                'schema' => $toolDefinition->getSchema(),
                'status' => $toolDefinition->getStatus(),
            ];
        }
        return $availableTools;
    }

    /**
     * Gibt ein bestimmtes Tool als Metadaten zurück.
     * @throws \InvalidArgumentException Falls das Tool nicht gefunden wurde
     */
    public function getTool(string $toolName): ToolDefinition
    {
        if (!$this->initialized) {
            $this->initialize();
        }

        if (!isset($this->tools[$toolName])) {
            throw new \InvalidArgumentException(sprintf(
                'Tool "%s" not found or not approved.',
                $toolName
            ));
        }

        return $this->tools[$toolName];
    }

    /**
     * Fügt ein neues Tool zum Registry hinzu.
     * Wird automatisch aufgerufen, wenn ein Tool genehmigt wird.
     */
    public function addTool(ToolDefinition $toolDefinition): void
    {
        if ($toolDefinition->isApproved()) {
            $this->tools[$toolDefinition->getName()] = $toolDefinition;
        }
    }

    /**
     * Entfernt ein Tool aus dem Registry.
     */
    public function removeTool(string $toolName): void
    {
        unset($this->tools[$toolName]);
    }

    /**
     * Prüft, ob ein Tool verfügbar ist.
     */
    public function hasTool(string $toolName): bool
    {
        if (!$this->initialized) {
            $this->initialize();
        }

        return isset($this->tools[$toolName]);
    }

    /**
     * Gibt die Metadaten eines Tools zurück.
     */
    public function getToolMetadata(string $toolName): ?array
    {
        if (!$this->initialized) {
            $this->initialize();
        }

        if (!$this->hasTool($toolName)) {
            return null;
        }

        $toolDefinition = $this->tools[$toolName];
        return [
            'name' => $toolDefinition->getName(),
            'description' => $toolDefinition->getDescription(),
            'schema' => $toolDefinition->getSchema(),
            'status' => $toolDefinition->getStatus(),
        ];
    }

    /**
     * Gibt alle Tool-Namen zurück.
     */
    public function getToolNames(): array
    {
        if (!$this->initialized) {
            $this->initialize();
        }

        return array_keys($this->tools);
    }

    /**
     * Gibt die Anzahl der verfügbaren Tools zurück.
     */
    public function countTools(): int
    {
        if (!$this->initialized) {
            $this->initialize();
        }

        return count($this->tools);
    }

    /**
     * Prüft, ob das Registry initialisiert wurde.
     */
    public function isInitialized(): bool
    {
        return $this->initialized;
    }
}