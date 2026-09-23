<?php
// src/Mcp/Toolbox/McpToolExecutor.php

namespace App\Mcp\Toolbox;

use App\Mcp\Client\McpServerManager;
use App\Mcp\Exception\McpServerUnavailableException;
use App\Mcp\Exception\McpToolExecutionFailed;

final class McpToolExecutor
{
    /** @var string[] */
    private array $knownServerAliases;

    public function __construct(
        private readonly McpServerManager $serverManager,
        array $serverAliases = [],
    ) {
        $this->knownServerAliases = $serverAliases;
    }

    public function __invoke(string $serverAlias, string $toolName, array $arguments = []): mixed
    {
        // Prüfe, ob der angefragte Server überhaupt existiert.
        // Das verhindert, dass das LLM erfundene Server-Aliasse (z. B. "weather_server")
        // verwendet, die nicht konfiguriert sind.
        // Frontend-Freigaben: Zusaetzlich zur statischen Alias-Liste wird der
        // McpServerManager befragt, dessen Konfigurations-Merge die aktiven
        // McpServerDefinition-Eintraege (/mcp/servers) enthaelt. Ein im
        // Frontend freigegebener Server ist damit ohne YAML-Aenderung
        // ausfuehrbar; erfundene Aliasse bleiben blockiert.
        if ($this->knownServerAliases !== []
            && !in_array($serverAlias, $this->knownServerAliases, true)
            && !$this->serverManager->hasServer($serverAlias)) {
            $available = implode(', ', array_unique([
                ...$this->knownServerAliases,
                ...$this->serverManager->getAvailableServerAliases(),
            ]));
            throw new McpToolExecutionFailed(
                $serverAlias,
                $toolName,
                sprintf(
                    'Unbekannter MCP-Server "%s". Konfigurierte Server sind: %s. ' .
                    'Nutze KEINE erfundenen Server-Aliasse. Prüfe, ob ein direktes Tool (z. B. weather) existiert.',
                    $serverAlias,
                    $available
                )
            );
        }

        try {
            return $this->serverManager->callTool($serverAlias, $toolName, $arguments);
        } catch (McpServerUnavailableException $e) {
            throw new McpToolExecutionFailed($serverAlias, $toolName, $e->getMessage());
        } catch (\Throwable $e) {
            throw new McpToolExecutionFailed($serverAlias, $toolName, $e->getMessage());
        }
    }
}
