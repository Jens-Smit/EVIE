<?php
// src/AI/Mcp/McpServerFactory.php

namespace App\AI\Mcp;

use App\Entity\McpServerDefinition;
use App\Repository\McpServerDefinitionRepository;
use App\AI\Security\SecurityGuard;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Factory für die dynamische Erstellung von MCP-Servern.
 * Unterstützt:
 * - Dynamisches Laden aus der Datenbank (McpServerDefinition)
 * - Fallback zu statischer Konfiguration (ai.yaml)
 * - Sicherheitsprüfung durch SecurityGuard
 */
class McpServerFactory
{
    private ContainerInterface $container;
    private McpServerDefinitionRepository $mcpServerDefinitionRepo;
    private SecurityGuard $securityGuard;
    private LoggerInterface $logger;

    public function __construct(
        ContainerInterface $container,
        McpServerDefinitionRepository $mcpServerDefinitionRepo,
        SecurityGuard $securityGuard,
        LoggerInterface $logger
    ) {
        $this->container = $container;
        $this->mcpServerDefinitionRepo = $mcpServerDefinitionRepo;
        $this->securityGuard = $securityGuard;
        $this->logger = $logger;
    }

    /**
     * Erstellt einen MCP-Server basierend auf einer Definition aus der Datenbank.
     * @throws \RuntimeException Falls der Server nicht erstellt werden kann
     */
    public function createFromDefinition(McpServerDefinition $definition): McpServerInterface
    {
        $name = $definition->getName();
        $type = $definition->getType();
        $configuration = $definition->getConfiguration();

        $this->logger->info('Erstelle MCP-Server aus Datenbank-Definition', [
            'name' => $name,
            'type' => $type,
        ]);

        // 1. Prüfe die Sicherheit der Konfiguration
        $this->validateServerConfiguration($definition);

        // 2. Erstelle den MCP-Server basierend auf dem Typ
        $server = $this->createServerByType($type, $configuration);

        // 3. Konfiguriere den Server mit den Definitionen
        $this->configureServer($server, $definition);

        $this->logger->info('MCP-Server aus Definition erstellt', [
            'name' => $name,
            'type' => $type,
        ]);

        return $server;
    }

    /**
     * Erstellt alle aktiven MCP-Server aus der Datenbank.
     * @return McpServerInterface[]
     */
    public function createAllFromDatabase(): array
    {
        $definitions = $this->mcpServerDefinitionRepo->findAllActive();
        $servers = [];

        foreach ($definitions as $definition) {
            try {
                $server = $this->createFromDefinition($definition);
                $servers[$definition->getName()] = $server;
            } catch (\Exception $e) {
                $this->logger->error('Fehler beim Laden des MCP-Servers aus Definition', [
                    'name' => $definition->getName(),
                    'type' => $definition->getType(),
                    'error' => $e->getMessage(),
                ]);
                continue;
            }
        }

        return $servers;
    }

    /**
     * Erstellt einen MCP-Server basierend auf einem Namen.
     * 1. Versucht, aus der Datenbank zu laden
     * 2. Fallback: Statische Konfiguration aus ai.yaml
     * @throws \RuntimeException Falls der Server nicht gefunden wurde
     */
    public function createByName(string $name): McpServerInterface
    {
        // 1. Versuche, aus der Datenbank zu laden
        $definition = $this->mcpServerDefinitionRepo->findOneByName($name);
        if ($definition !== null) {
            return $this->createFromDefinition($definition);
        }

        // 2. Fallback: Statische Konfiguration aus ai.yaml
        return $this->createFromStaticConfig($name);
    }

    /**
     * Erstellt einen MCP-Server aus der statischen Konfiguration (ai.yaml).
     * @throws \RuntimeException Falls der Server nicht gefunden wurde
     */
    private function createFromStaticConfig(string $name): McpServerInterface
    {
        $serviceId = $this->getServiceIdForStaticConfig($name);

        $this->logger->info('Erstelle MCP-Server aus statischer Konfiguration', [
            'name' => $name,
            'service_id' => $serviceId,
        ]);

        if (!$this->container->has($serviceId)) {
            throw new \RuntimeException(sprintf(
                'MCP-Server "%s" nicht in statischer Konfiguration gefunden (Service: %s).',
                $name,
                $serviceId
            ));
        }

        $server = $this->container->get($serviceId);

        if (!$server instanceof McpServerInterface) {
            throw new \RuntimeException(sprintf(
                'Service "%s" implementiert McpServerInterface nicht.',
                $serviceId
            ));
        }

        return $server;
    }

    /**
     * Gibt die Service-ID für einen MCP-Server aus der statischen Konfiguration zurück.
     */
    private function getServiceIdForStaticConfig(string $name): string
    {
        // Service-ID-Pattern für MCP-Server aus ai.yaml
        return 'ai.mcp.server.' . $name;
    }

    /**
     * Erstellt einen MCP-Server basierend auf dem Typ.
     * @throws \RuntimeException Falls der Server-Typ nicht unterstützt wird
     */
    private function createServerByType(string $type, array $configuration): McpServerInterface
    {
        $serviceId = $this->getServiceIdForType($type);

        if (!$this->container->has($serviceId)) {
            throw new \RuntimeException(sprintf(
                'MCP-Server-Typ "%s" wird nicht unterstützt oder Service "%s" existiert nicht.',
                $type,
                $serviceId
            ));
        }

        $server = $this->container->get($serviceId);

        if (!$server instanceof McpServerInterface) {
            throw new \RuntimeException(sprintf(
                'Service "%s" für Typ "%s" implementiert McpServerInterface nicht.',
                $serviceId,
                $type
            ));
        }

        return $server;
    }

    /**
     * Gibt die Service-ID für einen MCP-Server-Typ zurück.
     */
    private function getServiceIdForType(string $type): string
    {
        // Mapping von Typen zu Service-IDs
        $typeMap = [
            'filesystem' => 'ai.mcp.server.filesystem',
            'playwright' => 'ai.mcp.server.playwright',
            'github' => 'ai.mcp.server.github',
            'custom' => 'ai.mcp.server.custom',
        ];

        return $typeMap[$type] ?? 'ai.mcp.server.' . $type;
    }

    /**
     * Konfiguriert einen MCP-Server mit den Definitionen.
     */
    private function configureServer(McpServerInterface $server, McpServerDefinition $definition): void
    {
        // Setze die Konfiguration
        $server->setConfiguration($definition->getConfiguration());

        // Setze die Whitelist für Tools
        if (!empty($definition->getAllowedTools())) {
            $server->setAllowedTools($definition->getAllowedTools());
        }

        // Setze die Blocklist für Ressourcen
        if (!empty($definition->getBlockedResources())) {
            $server->setBlockedResources($definition->getBlockedResources());
        }
    }

    /**
     * Validiert die Konfiguration eines MCP-Servers gegen die SecurityGuard-Whitelist.
     * @throws \RuntimeException Falls die Konfiguration unsicher ist
     */
    private function validateServerConfiguration(McpServerDefinition $definition): void
    {
        $name = $definition->getName();
        $type = $definition->getType();
        $configuration = $definition->getConfiguration();

        // 1. Prüfe, ob der Server-Typ erlaubt ist
        if (!$this->securityGuard->isServiceAllowed($this->getServiceIdForType($type))) {
            throw new \RuntimeException(sprintf(
                'MCP-Server-Typ "%s" ist nicht in der SecurityGuard-Whitelist.',
                $type
            ));
        }

        // 2. Prüfe spezifische Konfigurationen
        switch ($type) {
            case 'filesystem':
                $this->validateFilesystemConfiguration($configuration, $name);
                break;
            case 'playwright':
                $this->validatePlaywrightConfiguration($configuration, $name);
                break;
            case 'github':
                $this->validateGithubConfiguration($configuration, $name);
                break;
            case 'custom':
                $this->validateCustomConfiguration($configuration, $name);
                break;
        }

        $this->logger->info('MCP-Server-Konfiguration validiert', [
            'name' => $name,
            'type' => $type,
        ]);
    }

    /**
     * Validiert die Konfiguration für einen Filesystem-MCP-Server.
     */
    private function validateFilesystemConfiguration(array $configuration, string $serverName): void
    {
        // Prüfe, ob die Pfade in der Whitelist sind
        if (isset($configuration['command'])) {
            $command = $configuration['command'];
            if (!$this->securityGuard->isServiceAllowed($command)) {
                throw new \RuntimeException(sprintf(
                    'Command "%s" für MCP-Server "%s" ist nicht in der SecurityGuard-Whitelist.',
                    $command,
                    $serverName
                ));
            }
        }

        if (isset($configuration['arguments'])) {
            foreach ($configuration['arguments'] as $arg) {
                if (!is_string($arg)) {
                    continue;
                }
                // P1-2: jedes Start-Argument gegen die Ressourcen-Blocklist
                // prüfen (Pfad-/URL-Sandbox).
                if ($this->securityGuard->isResourceBlocked($arg)) {
                    throw new \RuntimeException(sprintf(
                        'Argument "%s" für MCP-Server "%s" ist in der SecurityGuard-Blocklist.',
                        $arg,
                        $serverName
                    ));
                }
                // P1-2: Shell-Metazeichen in Start-Argumenten verbieten
                // (Command-Chaining / -Substitution), da npx/node/python/docker
                // Argumente ungefiltert an Subprozesse weiterreichen.
                if ($this->securityGuard->containsShellMetacharacters($arg)) {
                    throw new \RuntimeException(sprintf(
                        'Argument "%s" für MCP-Server "%s" enthält Shell-Metazeichen (mögliche Command-Injection).',
                        $arg,
                        $serverName
                    ));
                }
            }
        }
    }

    /**
     * Validiert die Konfiguration für einen Playwright-MCP-Server.
     */
    private function validatePlaywrightConfiguration(array $configuration, string $serverName): void
    {
        if (isset($configuration['command'])) {
            $command = $configuration['command'];
            if (!$this->securityGuard->isServiceAllowed($command)) {
                throw new \RuntimeException(sprintf(
                    'Command "%s" für Playwright-Server "%s" ist nicht in der SecurityGuard-Whitelist.',
                    $command,
                    $serverName
                ));
            }
        }
    }

    /**
     * Validiert die Konfiguration für einen GitHub-MCP-Server.
     */
    private function validateGithubConfiguration(array $configuration, string $serverName): void
    {
        if (isset($configuration['url'])) {
            $url = $configuration['url'];
            if ($this->securityGuard->isResourceBlocked($url)) {
                throw new \RuntimeException(sprintf(
                    'URL "%s" für GitHub-Server "%s" ist in der SecurityGuard-Blocklist.',
                    $url,
                    $serverName
                ));
            }
        }
    }

    /**
     * Validiert die Konfiguration für einen Custom-MCP-Server.
     */
    private function validateCustomConfiguration(array $configuration, string $serverName): void
    {
        // Custom-Server müssen manuell validiert werden
        if (isset($configuration['class'])) {
            $className = $configuration['class'];
            if (!$this->securityGuard->isServiceAllowed($className)) {
                throw new \RuntimeException(sprintf(
                    'Klasse "%s" für Custom-Server "%s" ist nicht in der SecurityGuard-Whitelist.',
                    $className,
                    $serverName
                ));
            }
        }
    }

    /**
     * Registriert einen neuen MCP-Server dynamisch in der Datenbank.
     */
    public function registerMcpServer(McpServerDefinition $definition): void
    {
        $entityManager = $this->container->get('doctrine.orm.entity_manager');
        
        // Validieren vor dem Speichern
        $this->validateServerConfiguration($definition);
        
        $entityManager->persist($definition);
        $entityManager->flush();

        $this->logger->info('MCP-Server-Definition registriert', [
            'name' => $definition->getName(),
            'type' => $definition->getType(),
        ]);
    }

    /**
     * Gibt alle verfügbaren MCP-Server zurück (dynamisch + statisch).
     * @return McpServerInterface[]
     */
    public function getAvailableServers(): array
    {
        // 1. Lade dynamische MCP-Server aus der DB
        $dynamicServers = $this->createAllFromDatabase();

        // 2. Füge statische MCP-Server hinzu (falls nicht in DB)
        $staticServers = $this->createStaticServers();

        // 3. Merge: Dynamische Server überschreiben statische
        return array_merge($staticServers, $dynamicServers);
    }

    /**
     * Erstellt alle statischen MCP-Server aus der ai.yaml-Konfiguration.
     * @return McpServerInterface[]
     */
    private function createStaticServers(): array
    {
        $servers = [];
        $staticServerNames = ['filesystem', 'playwright', 'github'];

        foreach ($staticServerNames as $name) {
            try {
                $server = $this->createFromStaticConfig($name);
                $servers[$name] = $server;
            } catch (\Exception $e) {
                $this->logger->warning('Statischer MCP-Server konnte nicht geladen werden', [
                    'name' => $name,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }
        }

        return $servers;
    }

    /**
     * Gibt alle aktiven MCP-Server-Definitionen aus der Datenbank zurück.
     * @return McpServerDefinition[]
     */
    public function getActiveServerDefinitions(): array
    {
        return $this->mcpServerDefinitionRepo->findAllActive();
    }

    /**
     * Gibt alle MCP-Server-Definitionen eines bestimmten Typs zurück.
     * @return McpServerDefinition[]
     */
    public function getServerDefinitionsByType(string $type): array
    {
        return $this->mcpServerDefinitionRepo->findByType($type);
    }
}
