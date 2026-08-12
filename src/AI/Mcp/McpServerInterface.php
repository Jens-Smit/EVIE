<?php
// src/AI/Mcp/McpServerInterface.php

namespace App\AI\Mcp;

/**
 * Interface für MCP-Server.
 * Definiert die Methoden, die alle MCP-Server implementieren müssen.
 */
interface McpServerInterface
{
    /**
     * Gibt den Namen des MCP-Servers zurück.
     */
    public function getName(): string;

    /**
     * Gibt den Typ des MCP-Servers zurück (z. B. 'filesystem', 'playwright', 'github').
     */
    public function getType(): string;

    /**
     * Gibt die Beschreibung des MCP-Servers zurück.
     */
    public function getDescription(): string;

    /**
     * Setzt die Konfiguration des MCP-Servers.
     * @param array $configuration Konfiguration als assoziatives Array
     */
    public function setConfiguration(array $configuration): void;

    /**
     * Gibt die Konfiguration des MCP-Servers zurück.
     * @return array Konfiguration als assoziatives Array
     */
    public function getConfiguration(): array;

    /**
     * Setzt die Whitelist für erlaubte Tools.
     * @param array $allowedTools Array mit Tool-Namen
     */
    public function setAllowedTools(array $allowedTools): void;

    /**
     * Gibt die Whitelist für erlaubte Tools zurück.
     * @return array Array mit Tool-Namen
     */
    public function getAllowedTools(): array;

    /**
     * Setzt die Blocklist für Ressourcen.
     * @param array $blockedResources Array mit Ressourcen-Patterns
     */
    public function setBlockedResources(array $blockedResources): void;

    /**
     * Gibt die Blocklist für Ressourcen zurück.
     * @return array Array mit Ressourcen-Patterns
     */
    public function getBlockedResources(): array;

    /**
     * Führt ein MCP-Tool aus.
     * @param string $toolName Name des Tools
     * @param array $arguments Argumente für das Tool
     * @return mixed Ergebnis der Tool-Ausführung
     * @throws \RuntimeException Falls das Tool nicht ausgeführt werden kann
     */
    public function executeTool(string $toolName, array $arguments = []): mixed;

    /**
     * Gibt die verfügbaren Tools des MCP-Servers zurück.
     * @return array Array mit Tool-Namen und Beschreibungen
     */
    public function getAvailableTools(): array;

    /**
     * Prüft, ob ein Tool auf diesem Server verfügbar ist.
     * @param string $toolName Name des Tools
     * @return bool True, wenn das Tool verfügbar ist
     */
    public function hasTool(string $toolName): bool;

    /**
     * Prüft, ob ein Tool erlaubt ist (Whitelist).
     * @param string $toolName Name des Tools
     * @return bool True, wenn das Tool erlaubt ist
     */
    public function isToolAllowed(string $toolName): bool;

    /**
     * Prüft, ob eine Ressource blockiert ist.
     * @param string $resource Ressource (z. B. Dateipfad, URL)
     * @return bool True, wenn die Ressource blockiert ist
     */
    public function isResourceBlocked(string $resource): bool;

    /**
     * Initialisiert den MCP-Server.
     * Wird aufgerufen, nachdem die Konfiguration gesetzt wurde.
     */
    public function initialize(): void;

    /**
     * Gibt den Status des MCP-Servers zurück.
     * @return string Status (z. B. 'initialized', 'error', 'connecting')
     */
    public function getStatus(): string;
}
