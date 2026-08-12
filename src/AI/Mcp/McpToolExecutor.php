<?php
// src/AI/Mcp/McpToolExecutor.php

namespace App\AI\Mcp;

use App\AI\Security\SecurityGuard;
use Psr\Log\LoggerInterface;

/**
 * Executor für MCP-Tools.
 * Führt Tools auf MCP-Servern aus und unterstützt dynamische Server-Konfiguration.
 */
class McpToolExecutor
{
    private McpServerFactory $mcpServerFactory;
    private SecurityGuard $securityGuard;
    private LoggerInterface $logger;

    public function __construct(
        McpServerFactory $mcpServerFactory,
        SecurityGuard $securityGuard,
        LoggerInterface $logger
    ) {
        $this->mcpServerFactory = $mcpServerFactory;
        $this->securityGuard = $securityGuard;
        $this->logger = $logger;
    }

    /**
     * Führt ein MCP-Tool auf einem bestimmten Server aus.
     * @param string $serverName Name des MCP-Servers
     * @param string $toolName Name des Tools
     * @param array $arguments Argumente für das Tool
     * @return mixed Ergebnis der Tool-Ausführung
     * @throws \RuntimeException Falls das Tool nicht ausgeführt werden kann
     */
    public function execute(string $serverName, string $toolName, array $arguments = []): mixed
    {
        $this->logger->info('Führe MCP-Tool aus', [
            'server' => $serverName,
            'tool' => $toolName,
        ]);

        // 1. Lade den MCP-Server dynamisch
        $server = $this->mcpServerFactory->createByName($serverName);

        // 2. Prüfe, ob das Tool verfügbar ist
        if (!$server->hasTool($toolName)) {
            throw new \RuntimeException(sprintf(
                'Tool "%s" ist auf MCP-Server "%s" nicht verfügbar.',
                $toolName,
                $serverName
            ));
        }

        // 3. Prüfe, ob das Tool erlaubt ist (Whitelist)
        if (!$server->isToolAllowed($toolName)) {
            throw new \RuntimeException(sprintf(
                'Tool "%s" ist auf MCP-Server "%s" nicht erlaubt.',
                $toolName,
                $serverName
            ));
        }

        // 4. Prüfe die Sicherheit des Tools durch SecurityGuard
        if (!$this->securityGuard->isToolAllowed($toolName)) {
            throw new \RuntimeException(sprintf(
                'Tool "%s" wurde durch SecurityGuard blockiert.',
                $toolName
            ));
        }

        // 5. Prüfe, ob Ressourcen in den Argumenten blockiert sind
        $this->validateArguments($server, $arguments);

        // 6. Führe das Tool aus
        try {
            $result = $server->executeTool($toolName, $arguments);
            
            $this->logger->info('MCP-Tool erfolgreich ausgeführt', [
                'server' => $serverName,
                'tool' => $toolName,
            ]);

            return $result;
        } catch (\Exception $e) {
            $this->logger->error('Fehler bei der Ausführung des MCP-Tools', [
                'server' => $serverName,
                'tool' => $toolName,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Validiert die Argumente gegen die Blocklist des MCP-Servers.
     * @throws \RuntimeException Falls eine Ressource blockiert ist
     */
    private function validateArguments(McpServerInterface $server, array $arguments): void
    {
        foreach ($arguments as $key => $value) {
            if (is_string($value) && $server->isResourceBlocked($value)) {
                throw new \RuntimeException(sprintf(
                    'Ressource "%s" ist auf diesem MCP-Server blockiert.',
                    $value
                ));
            }

            if (is_array($value)) {
                $this->validateArguments($server, $value);
            }
        }
    }

    /**
     * Gibt alle verfügbaren MCP-Server zurück.
     * @return McpServerInterface[]
     */
    public function getAvailableServers(): array
    {
        return $this->mcpServerFactory->getAvailableServers();
    }

    /**
     * Gibt alle verfügbaren Tools eines MCP-Servers zurück.
     * @param string $serverName Name des MCP-Servers
     * @return array Array mit Tool-Namen und Beschreibungen
     * @throws \RuntimeException Falls der Server nicht gefunden wurde
     */
    public function getServerTools(string $serverName): array
    {
        $server = $this->mcpServerFactory->createByName($serverName);
        return $server->getAvailableTools();
    }

    /**
     * Prüft, ob ein Tool auf einem MCP-Server verfügbar ist.
     * @param string $serverName Name des MCP-Servers
     * @param string $toolName Name des Tools
     * @return bool True, wenn das Tool verfügbar ist
     */
    public function hasServerTool(string $serverName, string $toolName): bool
    {
        try {
            $server = $this->mcpServerFactory->createByName($serverName);
            return $server->hasTool($toolName);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Prüft, ob ein Tool auf einem MCP-Server erlaubt ist.
     * @param string $serverName Name des MCP-Servers
     * @param string $toolName Name des Tools
     * @return bool True, wenn das Tool erlaubt ist
     */
    public function isToolAllowed(string $serverName, string $toolName): bool
    {
        try {
            $server = $this->mcpServerFactory->createByName($serverName);
            return $server->isToolAllowed($toolName);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Gibt alle aktiven MCP-Server-Definitionen aus der Datenbank zurück.
     * @return McpServerDefinition[]
     */
    public function getActiveServerDefinitions(): array
    {
        return $this->mcpServerFactory->getActiveServerDefinitions();
    }

    /**
     * Führt ein MCP-Tool aus, indem automatisch der passende Server bestimmt wird.
     * @param string $toolName Name des Tools
     * @param array $arguments Argumente für das Tool
     * @return mixed Ergebnis der Tool-Ausführung
     * @throws \RuntimeException Falls kein passender Server gefunden wurde
     */
    public function executeTool(string $toolName, array $arguments = []): mixed
    {
        // 1. Finde alle Server, die das Tool unterstützen
        $servers = $this->mcpServerFactory->getAvailableServers();
        $suitableServers = [];

        foreach ($servers as $serverName => $server) {
            if ($server->hasTool($toolName) && $server->isToolAllowed($toolName)) {
                $suitableServers[$serverName] = $server;
            }
        }

        if (empty($suitableServers)) {
            throw new \RuntimeException(sprintf(
                'Kein MCP-Server gefunden, der Tool "%s" unterstützt.',
                $toolName
            ));
        }

        // 2. Verwende den ersten passenden Server
        $serverName = key($suitableServers);
        return $this->execute($serverName, $toolName, $arguments);
    }
}
