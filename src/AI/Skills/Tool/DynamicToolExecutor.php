<?php

namespace App\AI\Skills\Tool;

use App\AI\Security\SecurityGuard;
use App\Entity\ToolDefinition;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * DynamicToolExecutor - Führt dynamisch generierte Tools aus
 * 
 * Basierend auf dem Tool-Schema wird die passende Ausführungslogik gewählt.
 * Integriert SecurityGuard für Sicherheitsprüfungen.
 */
final readonly class DynamicToolExecutor
{
    public function __construct(
        private ContainerInterface $container,
        private SecurityGuard $securityGuard,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Führt ein dynamisches Tool aus.
     * 
     * @param ToolDefinition $toolDefinition Die Tool-Definition
     * @param array $arguments Die Argumente für das Tool
     * @return mixed Das Ergebnis der Ausführung
     * @throws \RuntimeException Falls die Ausführung fehlschlägt
     */
    public function execute(ToolDefinition $toolDefinition, array $arguments = []): mixed
    {
        $schema = $toolDefinition->getSchema();
        $toolName = $toolDefinition->getName();

        $this->logger->debug('DynamicToolExecutor: Ausführung gestartet', [
            'tool' => $toolName,
            'schema' => $schema,
            'arguments' => $arguments,
        ]);

        // 1. Sicherheitsprüfung
        $this->securityGuard->assertToolAllowed($schema, $toolName);

        // 2. Führe das Tool basierend auf dem Schema aus
        try {
            // Prüfe, ob das Tool einen spezifischen Service referenziert
            if (isset($schema['service'])) {
                return $this->executeServiceTool($toolDefinition, $arguments);
            }

            // Prüfe, ob das Tool einen Typ hat
            if (isset($schema['type'])) {
                return $this->executeByType($toolDefinition, $arguments);
            }

            // Standard-Ausführung
            return $this->executeGenericTool($toolDefinition, $arguments);

        } catch (\Exception $e) {
            $this->logger->error('DynamicToolExecutor: Ausführung fehlgeschlagen', [
                'tool' => $toolName,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw new \RuntimeException(sprintf(
                'Ausführung des Tools "%s" fehlgeschlagen: %s',
                $toolName,
                $e->getMessage()
            ), 0, $e);
        }
    }

    /**
     * Führt ein Tool aus, das einen spezifischen Service referenziert.
     */
    private function executeServiceTool(ToolDefinition $toolDefinition, array $arguments): mixed
    {
        $schema = $toolDefinition->getSchema();
        $serviceClass = $schema['service'];

        // 1. Prüfe, ob der Service existiert
        if (!$this->container->has($serviceClass)) {
            throw new \RuntimeException(sprintf(
                'Service "%s" für Tool "%s" nicht gefunden.',
                $serviceClass,
                $toolDefinition->getName()
            ));
        }

        // 2. Hole den Service
        $service = $this->container->get($serviceClass);

        // 3. Führe den Service aus
        if (method_exists($service, '__invoke')) {
            return $service->__invoke(...$arguments);
        }

        if (method_exists($service, 'execute')) {
            return $service->execute(...$arguments);
        }

        if (method_exists($service, 'run')) {
            return $service->run(...$arguments);
        }

        throw new \RuntimeException(sprintf(
            'Service "%s" hat keine ausführbare Methode (__invoke, execute oder run).',
            $serviceClass
        ));
    }

    /**
     * Führt ein Tool basierend auf seinem Typ aus.
     */
    private function executeByType(ToolDefinition $toolDefinition, array $arguments): mixed
    {
        $schema = $toolDefinition->getSchema();
        $type = $schema['type'] ?? 'object';

        switch ($type) {
            case 'api':
                return $this->executeApiTool($toolDefinition, $arguments);

            case 'database':
                return $this->executeDatabaseTool($toolDefinition, $arguments);

            case 'filesystem':
                return $this->executeFilesystemTool($toolDefinition, $arguments);

            case 'http':
                return $this->executeHttpTool($toolDefinition, $arguments);

            case 'script':
                return $this->executeScriptTool($toolDefinition, $arguments);

            default:
                return $this->executeGenericTool($toolDefinition, $arguments);
        }
    }

    /**
     * Führt ein API-Tool aus.
     */
    private function executeApiTool(ToolDefinition $toolDefinition, array $arguments): mixed
    {
        $schema = $toolDefinition->getSchema();
        
        // Hole API-Executor aus dem Container
        if (!$this->container->has('App\AI\Skills\Tool\GenericApiExecutor')) {
            throw new \RuntimeException('GenericApiExecutor nicht verfügbar.');
        }

        $apiExecutor = $this->container->get('App\AI\Skills\Tool\GenericApiExecutor');
        
        // Extrahiere API-Konfiguration aus dem Schema
        $apiConfig = $schema['api_config'] ?? [];
        
        // Führe API-Aufruf aus
        return $apiExecutor->__invoke(
            $apiConfig['endpoint'] ?? '',
            $apiConfig['method'] ?? 'GET',
            $arguments,
            $apiConfig['headers'] ?? [],
            $apiConfig['options'] ?? []
        );
    }

    /**
     * Führt ein Datenbank-Tool aus.
     */
    private function executeDatabaseTool(ToolDefinition $toolDefinition, array $arguments): mixed
    {
        $schema = $toolDefinition->getSchema();
        
        // Hole DatabaseQueryExecutor aus dem Container
        if (!$this->container->has('App\AI\Skills\Tool\DatabaseQueryExecutor')) {
            throw new \RuntimeException('DatabaseQueryExecutor nicht verfügbar.');
        }

        $dbExecutor = $this->container->get('App\AI\Skills\Tool\DatabaseQueryExecutor');
        
        // Extrahiere Query-Konfiguration aus dem Schema
        $queryConfig = $schema['query_config'] ?? [];
        
        // Führe Datenbankabfrage aus
        return $dbExecutor->__invoke(
            $queryConfig['query'] ?? '',
            $arguments,
            $queryConfig['connection'] ?? 'default'
        );
    }

    /**
     * Führt ein Dateisystem-Tool aus.
     */
    private function executeFilesystemTool(ToolDefinition $toolDefinition, array $arguments): mixed
    {
        $schema = $toolDefinition->getSchema();
        
        // Hole FileSystemReadExecutor aus dem Container
        if (!$this->container->has('App\AI\Skills\Tool\FileSystemReadExecutor')) {
            throw new \RuntimeException('FileSystemReadExecutor nicht verfügbar.');
        }

        $fsExecutor = $this->container->get('App\AI\Skills\Tool\FileSystemReadExecutor');
        
        // Extrahiere Dateipfad aus den Argumenten
        $path = $arguments['path'] ?? ($schema['path'] ?? '');
        
        // Führe Dateioperation aus
        return $fsExecutor->__invoke($path);
    }

    /**
     * Führt ein HTTP-Tool aus.
     */
    private function executeHttpTool(ToolDefinition $toolDefinition, array $arguments): mixed
    {
        $schema = $toolDefinition->getSchema();
        
        // Hole HttpClientExecutor aus dem Container
        if (!$this->container->has('App\AI\Skills\Tool\HttpClientExecutor')) {
            throw new \RuntimeException('HttpClientExecutor nicht verfügbar.');
        }

        $httpExecutor = $this->container->get('App\AI\Skills\Tool\HttpClientExecutor');
        
        // Extrahiere HTTP-Konfiguration aus dem Schema
        $httpConfig = $schema['http_config'] ?? [];
        
        // Führe HTTP-Anfrage aus
        return $httpExecutor->__invoke(
            $httpConfig['url'] ?? '',
            $httpConfig['method'] ?? 'GET',
            $arguments,
            $httpConfig['headers'] ?? [],
            $httpConfig['options'] ?? []
        );
    }

    /**
     * Führt ein Script-Tool aus (z. B. PHP-Code).
     * 
     * ⚠️ ACHTUNG: Script-Ausführung ist deaktiviert, da sie Sicherheitsrisiken birgt.
     */
    private function executeScriptTool(ToolDefinition $toolDefinition, array $arguments): mixed
    {
        throw new \RuntimeException(
            'Script-Ausführung ist aus Sicherheitsgründen deaktiviert. ' .
            'Verwende stattdessen spezifische Tool-Typen wie api, database, filesystem oder http.'
        );
    }

    /**
     * Standard-Ausführung für generische Tools.
     * 
     * Gibt eine Bestätigungsnachricht zurück, da keine spezifische Logik definiert ist.
     */
    private function executeGenericTool(ToolDefinition $toolDefinition, array $arguments): mixed
    {
        $this->logger->info('DynamicToolExecutor: Generische Tool-Ausführung', [
            'tool' => $toolDefinition->getName(),
            'arguments' => $arguments,
        ]);

        return [
            'status' => 'success',
            'message' => sprintf(
                'Tool "%s" wurde erfolgreich ausgeführt.',
                $toolDefinition->getName()
            ),
            'tool' => $toolDefinition->getName(),
            'arguments' => $arguments,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];
    }
}
