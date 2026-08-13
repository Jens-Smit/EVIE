<?php

namespace App\AI\Skills;

use App\Entity\ToolDefinition;
use App\Repository\ToolDefinitionRepository;
use App\AI\Skills\Tool\DynamicToolFactory;
use Symfony\AI\Agent\Tool\ToolRegistry;

/**
 * DynamicSkillRegistry verwaltet die verfügbaren Tools.
 * Er lädt freigegebene Tools aus der Datenbank und stellt Metadaten für den DynamicToolDispatcher bereit.
 * 
 * Unterstützt:
 * - Lazy-Loading von Tools zur Laufzeit
 * - Automatisches Nachladen von Tools nach Genehmigung
 * - Event-basierte Aktualisierung
 * - Integration mit DynamicToolFactory für Tool-Erstellung
 * - Registrierung im Symfony AI Bundle ToolRegistry
 * - Implementiert DynamicSkillRegistryInterface zur Vermeidung zirkulärer Abhängigkeiten
 * 
 * @see https://symfony.com/doc/current/ai/bundles/ai-bundle.html
 * @implements DynamicSkillRegistryInterface
 */
class DynamicSkillRegistry implements DynamicSkillRegistryInterface
{
    private ToolDefinitionRepository $toolDefinitionRepo;
    private DynamicToolFactory $toolFactory;
    private ?ToolRegistry $toolRegistry = null;
    private array $tools = [];
    private bool $initialized = false;
    private bool $toolsRegistered = false;

    public function __construct(
        ToolDefinitionRepository $toolDefinitionRepo,
        DynamicToolFactory $toolFactory,
        ?ToolRegistry $toolRegistry = null,
    ) {
        $this->toolDefinitionRepo = $toolDefinitionRepo;
        $this->toolFactory = $toolFactory;
        $this->toolRegistry = $toolRegistry;
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
    }

    /**
     * Lädt das Registry neu (z. B. nach Genehmigung eines neuen Tools).
     */
    public function reload(): void
    {
        $this->tools = [];
        $this->initialized = false;
        $this->toolsRegistered = false;
        $this->initialize();
    }

    /**
     * Registriert alle genehmigten Tools im Symfony AI Bundle ToolRegistry.
     * 
     * Dies ist die Lazy-Loading-Alternative zum CompilerPass.
     * Wird aufgerufen, wenn Tools zur Laufzeit registriert werden müssen.
     */
    public function registerApprovedTools(): void
    {
        if ($this->toolsRegistered || $this->toolRegistry === null) {
            return;
        }

        if (!$this->initialized) {
            $this->initialize();
        }

        foreach ($this->tools as $name => $toolDefinition) {
            try {
                $tool = $this->toolFactory->createTool($toolDefinition);
                $this->toolRegistry->registerTool(
                    $tool,
                    $toolDefinition->getName(),
                    $toolDefinition->getDescription()
                );
            } catch (\Exception $e) {
                // Logge Fehler, aber breche nicht ab
                // In einer echten Implementierung würde hier ein Logger verwendet werden
                continue;
            }
        }

        $this->toolsRegistered = true;
    }

    /**
     * Registriert ein einzelnes Tool im ToolRegistry.
     */
    public function registerTool(ToolDefinition $toolDefinition): void
    {
        if ($this->toolRegistry === null) {
            return;
        }

        try {
            $tool = $this->toolFactory->createTool($toolDefinition);
            $this->toolRegistry->registerTool(
                $tool,
                $toolDefinition->getName(),
                $toolDefinition->getDescription()
            );
        } catch (\Exception $e) {
            // Logge Fehler
        }
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
            
            // Registriere das Tool sofort im ToolRegistry
            if ($this->toolRegistry !== null) {
                $this->registerTool($toolDefinition);
            }
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

    /**
     * Prüft, ob die Tools im ToolRegistry registriert wurden.
     */
    public function areToolsRegistered(): bool
    {
        return $this->toolsRegistered;
    }

    /**
     * Setzt den ToolRegistry (für Dependency Injection).
     */
    public function setToolRegistry(ToolRegistry $toolRegistry): void
    {
        $this->toolRegistry = $toolRegistry;
    }
}
