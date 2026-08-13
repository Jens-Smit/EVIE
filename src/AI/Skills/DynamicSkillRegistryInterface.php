<?php

namespace App\AI\Skills;

use Symfony\AI\Agent\Tool\ToolInterface;

/**
 * Interface für DynamicSkillRegistry
 * 
 * Dieses Interface wird verwendet, um die zirkuläre Abhängigkeit zwischen
 * DynamicSkillRegistry, DynamicToolFactory und SubAgentFactory zu brechen.
 * 
 * @see ROADMAP_PHASE3.md Maßnahme 9: E2E-Test für Evolution-Flow
 */
interface DynamicSkillRegistryInterface
{
    /**
     * Initialisiert das Registry und lädt alle freigegebenen Tools.
     */
    public function initialize(): void;

    /**
     * Lädt alle freigegebenen Tools aus der Datenbank.
     */
    public function loadTools(): void;

    /**
     * Lädt das Registry neu (z. B. nach Genehmigung eines neuen Tools).
     */
    public function reload(): void;

    /**
     * Registriert alle genehmigten Tools im Symfony AI Bundle ToolRegistry.
     */
    public function registerApprovedTools(): void;

    /**
     * Registriert ein einzelnes Tool im ToolRegistry.
     */
    public function registerTool(\App\Entity\ToolDefinition $toolDefinition): void;

    /**
     * Gibt alle verfügbaren Tools als Metadaten zurück.
     * 
     * @return array<string, array{name: string, description: string, schema: array, status: string}>
     */
    public function getAvailableTools(): array;

    /**
     * Gibt ein bestimmtes Tool als Metadaten zurück.
     * 
     * @throws \InvalidArgumentException Falls das Tool nicht gefunden wurde
     */
    public function getTool(string $toolName): \App\Entity\ToolDefinition;

    /**
     * Fügt ein neues Tool zum Registry hinzu.
     */
    public function addTool(\App\Entity\ToolDefinition $toolDefinition): void;

    /**
     * Entfernt ein Tool aus dem Registry.
     */
    public function removeTool(string $toolName): void;

    /**
     * Prüft, ob ein Tool verfügbar ist.
     */
    public function hasTool(string $toolName): bool;

    /**
     * Gibt die Metadaten eines Tools zurück.
     */
    public function getToolMetadata(string $toolName): ?array;

    /**
     * Gibt alle Tool-Namen zurück.
     */
    public function getToolNames(): array;

    /**
     * Gibt die Anzahl der verfügbaren Tools zurück.
     */
    public function countTools(): int;

    /**
     * Prüft, ob das Registry initialisiert wurde.
     */
    public function isInitialized(): bool;

    /**
     * Prüft, ob die Tools im ToolRegistry registriert wurden.
     */
    public function areToolsRegistered(): bool;

    /**
     * Setzt den ToolRegistry (für Dependency Injection).
     */
    public function setToolRegistry(\Symfony\AI\Agent\Tool\ToolRegistry $toolRegistry): void;
}
