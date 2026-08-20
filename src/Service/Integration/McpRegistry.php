<?php

namespace App\Service\Integration;

use App\Entity\Tenant\Tenant;
use App\Entity\Integration\McpServer;
use App\Repository\Integration\McpServerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Ulid;

/**
 * McpRegistry manages MCP (Model Context Protocol) servers and tools.
 * 
 * Features:
 * - MCP server registration and discovery
 * - Tool listing and management
 * - Resource management
 * - MCP server health checks
 * - Tool capability matching
 */
class McpRegistry
{
    private const array BUILTIN_MCP_SERVERS = [
        [
            'identifier' => 'filesystem',
            'name' => 'Filesystem MCP',
            'description' => 'Access filesystem resources',
            'url' => 'http://filesystem-mcp:8123',
            'type' => 'server',
            'enabled' => true,
            'tools' => [
                [
                    'name' => 'read_resource',
                    'description' => 'Read a file or directory',
                    'parameters' => [
                        ['name' => 'uri', 'type' => 'string', 'required' => true],
                    ],
                ],
                [
                    'name' => 'write_resource',
                    'description' => 'Write to a file',
                    'parameters' => [
                        ['name' => 'uri', 'type' => 'string', 'required' => true],
                        ['name' => 'content', 'type' => 'string', 'required' => true],
                    ],
                ],
                [
                    'name' => 'list_resources',
                    'description' => 'List resources in a directory',
                    'parameters' => [
                        ['name' => 'uri', 'type' => 'string', 'required' => true],
                    ],
                ],
            ],
            'resources' => [
                ['name' => 'file', 'type' => 'file', 'description' => 'File resource'],
                ['name' => 'directory', 'type' => 'directory', 'description' => 'Directory resource'],
            ],
        ],
        [
            'identifier' => 'playwright',
            'name' => 'Playwright MCP',
            'description' => 'Browser automation and web scraping',
            'url' => 'http://playwright-mcp:8931/mcp',
            'type' => 'server',
            'enabled' => true,
            'tools' => [
                [
                    'name' => 'navigate',
                    'description' => 'Navigate to a URL',
                    'parameters' => [
                        ['name' => 'url', 'type' => 'string', 'required' => true],
                        ['name' => 'waitUntil', 'type' => 'string', 'required' => false],
                    ],
                ],
                [
                    'name' => 'click',
                    'description' => 'Click an element',
                    'parameters' => [
                        ['name' => 'selector', 'type' => 'string', 'required' => true],
                    ],
                ],
                [
                    'name' => 'fill',
                    'description' => 'Fill a form field',
                    'parameters' => [
                        ['name' => 'selector', 'type' => 'string', 'required' => true],
                        ['name' => 'value', 'type' => 'string', 'required' => true],
                    ],
                ],
                [
                    'name' => 'extract_text',
                    'description' => 'Extract text from the page',
                    'parameters' => [
                        ['name' => 'selector', 'type' => 'string', 'required' => false],
                    ],
                ],
                [
                    'name' => 'take_screenshot',
                    'description' => 'Take a screenshot',
                    'parameters' => [
                        ['name' => 'path', 'type' => 'string', 'required' => false],
                    ],
                ],
            ],
            'resources' => [],
        ],
        [
            'identifier' => 'github',
            'name' => 'GitHub MCP',
            'description' => 'GitHub repository access',
            'url' => 'http://github-mcp:8080',
            'type' => 'server',
            'enabled' => false, // Requires configuration
            'tools' => [
                [
                    'name' => 'get_issue',
                    'description' => 'Get a GitHub issue',
                    'parameters' => [
                        ['name' => 'owner', 'type' => 'string', 'required' => true],
                        ['name' => 'repo', 'type' => 'string', 'required' => true],
                        ['name' => 'issue_number', 'type' => 'integer', 'required' => true],
                    ],
                ],
                [
                    'name' => 'list_issues',
                    'description' => 'List GitHub issues',
                    'parameters' => [
                        ['name' => 'owner', 'type' => 'string', 'required' => true],
                        ['name' => 'repo', 'type' => 'string', 'required' => true],
                    ],
                ],
                [
                    'name' => 'create_issue',
                    'description' => 'Create a GitHub issue',
                    'parameters' => [
                        ['name' => 'owner', 'type' => 'string', 'required' => true],
                        ['name' => 'repo', 'type' => 'string', 'required' => true],
                        ['name' => 'title', 'type' => 'string', 'required' => true],
                        ['name' => 'body', 'type' => 'string', 'required' => false],
                    ],
                ],
                [
                    'name' => 'get_repository',
                    'description' => 'Get repository information',
                    'parameters' => [
                        ['name' => 'owner', 'type' => 'string', 'required' => true],
                        ['name' => 'repo', 'type' => 'string', 'required' => true],
                    ],
                ],
            ],
            'resources' => [
                ['name' => 'repository', 'type' => 'repository', 'description' => 'GitHub repository'],
                ['name' => 'issue', 'type' => 'issue', 'description' => 'GitHub issue'],
            ],
        ],
        [
            'identifier' => 'fetch',
            'name' => 'Fetch MCP',
            'description' => 'HTTP request tool',
            'url' => 'http://fetch-mcp:8080',
            'type' => 'server',
            'enabled' => true,
            'tools' => [
                [
                    'name' => 'fetch',
                    'description' => 'Make an HTTP request',
                    'parameters' => [
                        ['name' => 'url', 'type' => 'string', 'required' => true],
                        ['name' => 'method', 'type' => 'string', 'required' => false, 'default' => 'GET'],
                        ['name' => 'headers', 'type' => 'object', 'required' => false],
                        ['name' => 'body', 'type' => 'string', 'required' => false],
                    ],
                ],
            ],
            'resources' => [],
        ],
    ];

    public function __construct(
        private McpServerRepository $mcpServerRepository,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Get all built-in MCP server definitions.
     * 
     * @return array
     */
    public function getBuiltinMcpServers(): array
    {
        return self::BUILTIN_MCP_SERVERS;
    }

    /**
     * Get a built-in MCP server by identifier.
     * 
     * @param string $identifier The server identifier
     * @return array|null The server definition or null
     */
    public function getBuiltinMcpServer(string $identifier): ?array
    {
        foreach (self::BUILTIN_MCP_SERVERS as $server) {
            if ($server['identifier'] === $identifier) {
                return $server;
            }
        }
        return null;
    }

    /**
     * Register a built-in MCP server for a tenant.
     * 
     * @param Tenant $tenant The tenant
     * @param string $identifier The server identifier
     * @param array $overrides Override default configuration
     * @return McpServer The registered server
     */
    public function registerBuiltinMcpServer(
        Tenant $tenant,
        string $identifier,
        array $overrides = []
    ): McpServer {
        $builtin = $this->getBuiltinMcpServer($identifier);
        
        if ($builtin === null) {
            throw new \InvalidArgumentException("Unknown MCP server: {$identifier}");
        }

        // Check if server already exists for this tenant
        $existing = $this->mcpServerRepository->findOneByIdentifierAndTenant(
            $identifier,
            $tenant->getId()
        );

        if ($existing !== null) {
            $this->logger->warning('MCP server already registered, updating instead', [
                'identifier' => $identifier,
                'tenantId' => $tenant->getId(),
            ]);
            
            return $this->updateMcpServer($existing, $overrides);
        }

        // Create new MCP server
        $server = new McpServer();
        $server->setIdentifier($identifier);
        $server->setName($overrides['name'] ?? $builtin['name']);
        $server->setDescription($overrides['description'] ?? $builtin['description']);
        $server->setUrl($overrides['url'] ?? $builtin['url']);
        $server->setType($overrides['type'] ?? $builtin['type']);
        $server->setTools($overrides['tools'] ?? $builtin['tools']);
        $server->setResources($overrides['resources'] ?? $builtin['resources']);
        $server->setConfiguration($overrides['configuration'] ?? []);
        $server->setTenant($tenant);
        $server->setIsEnabled($overrides['enabled'] ?? $builtin['enabled']);
        $server->setIsConnected(false); // Will be connected on first use

        $this->entityManager->persist($server);
        $this->entityManager->flush();

        $this->logger->info('Registered built-in MCP server', [
            'identifier' => $identifier,
            'tenantId' => $tenant->getId(),
        ]);

        return $server;
    }

    /**
     * Register all built-in MCP servers for a tenant.
     * 
     * @param Tenant $tenant The tenant
     * @param array $exclude Identifiers to exclude
     * @return McpServer[] Registered servers
     */
    public function registerAllBuiltinMcpServers(
        Tenant $tenant,
        array $exclude = []
    ): array {
        $servers = [];
        
        foreach (self::BUILTIN_MCP_SERVERS as $builtin) {
            $identifier = $builtin['identifier'];
            
            if (in_array($identifier, $exclude, true)) {
                continue;
            }

            try {
                $server = $this->registerBuiltinMcpServer($tenant, $identifier);
                $servers[] = $server;
            } catch (\Exception $e) {
                $this->logger->error('Failed to register MCP server', [
                    'identifier' => $identifier,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $servers;
    }

    /**
     * Get all MCP servers for a tenant.
     * 
     * @param Tenant $tenant The tenant
     * @return McpServer[]
     */
    public function getMcpServersByTenant(Tenant $tenant): array
    {
        return $this->mcpServerRepository->findByTenant($tenant->getId());
    }

    /**
     * Get enabled MCP servers for a tenant.
     * 
     * @param Tenant $tenant The tenant
     * @return McpServer[]
     */
    public function getEnabledMcpServersByTenant(Tenant $tenant): array
    {
        return $this->mcpServerRepository->findEnabledByTenant($tenant->getId());
    }

    /**
     * Get connected MCP servers for a tenant.
     * 
     * @param Tenant $tenant The tenant
     * @return McpServer[]
     */
    public function getConnectedMcpServersByTenant(Tenant $tenant): array
    {
        return $this->mcpServerRepository->findConnectedByTenant($tenant->getId());
    }

    /**
     * Get an MCP server by identifier for a tenant.
     * 
     * @param Tenant $tenant The tenant
     * @param string $identifier The server identifier
     * @return McpServer|null
     */
    public function getMcpServerByIdentifier(Tenant $tenant, string $identifier): ?McpServer
    {
        return $this->mcpServerRepository->findOneByIdentifierAndTenant(
            $identifier,
            $tenant->getId()
        );
    }

    /**
     * Get all tools from all MCP servers for a tenant.
     * 
     * @param Tenant $tenant The tenant
     * @return array Array of tools
     */
    public function getAllTools(Tenant $tenant): array
    {
        $servers = $this->getEnabledMcpServersByTenant($tenant);
        $tools = [];

        foreach ($servers as $server) {
            $serverTools = $server->getTools();
            
            foreach ($serverTools as $tool) {
                $tool['serverIdentifier'] = $server->getIdentifier();
                $tool['serverName'] = $server->getName();
                $tools[] = $tool;
            }
        }

        return $tools;
    }

    /**
     * Get all resources from all MCP servers for a tenant.
     * 
     * @param Tenant $tenant The tenant
     * @return array Array of resources
     */
    public function getAllResources(Tenant $tenant): array
    {
        $servers = $this->getEnabledMcpServersByTenant($tenant);
        $resources = [];

        foreach ($servers as $server) {
            $serverResources = $server->getResources();
            
            if ($serverResources !== null) {
                foreach ($serverResources as $resource) {
                    $resource['serverIdentifier'] = $server->getIdentifier();
                    $resource['serverName'] = $server->getName();
                    $resources[] = $resource;
                }
            }
        }

        return $resources;
    }

    /**
     * Find tools by name.
     * 
     * @param Tenant $tenant The tenant
     * @param string $toolName The tool name
     * @return array Matching tools
     */
    public function findToolsByName(Tenant $tenant, string $toolName): array
    {
        $allTools = $this->getAllTools($tenant);
        $matching = [];

        foreach ($allTools as $tool) {
            if (str_contains(strtolower($tool['name']), strtolower($toolName))) {
                $matching[] = $tool;
            }
        }

        return $matching;
    }

    /**
     * Find resources by name.
     * 
     * @param Tenant $tenant The tenant
     * @param string $resourceName The resource name
     * @return array Matching resources
     */
    public function findResourcesByName(Tenant $tenant, string $resourceName): array
    {
        $allResources = $this->getAllResources($tenant);
        $matching = [];

        foreach ($allResources as $resource) {
            if (str_contains(strtolower($resource['name']), strtolower($resourceName))) {
                $matching[] = $resource;
            }
        }

        return $matching;
    }

    /**
     * Connect to an MCP server.
     * 
     * @param McpServer $server The server to connect to
     * @return bool True if connection was successful
     */
    public function connect(McpServer $server): bool
    {
        // In a real implementation, you would:
        // 1. Make a health check request to the MCP server
        // 2. Verify the connection
        // 3. Cache the connection status
        
        // For now, we'll simulate a connection
        $server->setIsConnected(true);
        $server->setLastConnectedAt(new \DateTimeImmutable());
        $this->entityManager->persist($server);
        $this->entityManager->flush();

        $this->logger->info('Connected to MCP server', [
            'serverId' => $server->getId(),
            'identifier' => $server->getIdentifier(),
        ]);

        return true;
    }

    /**
     * Disconnect from an MCP server.
     * 
     * @param McpServer $server The server to disconnect from
     * @return bool True if disconnection was successful
     */
    public function disconnect(McpServer $server): bool
    {
        $server->setIsConnected(false);
        $this->entityManager->persist($server);
        $this->entityManager->flush();

        $this->logger->info('Disconnected from MCP server', [
            'serverId' => $server->getId(),
            'identifier' => $server->getIdentifier(),
        ]);

        return true;
    }

    /**
     * Check if an MCP server is connected.
     * 
     * @param McpServer $server The server
     * @return bool
     */
    public function isConnected(McpServer $server): bool
    {
        return $server->isConnected();
    }

    /**
     * Enable an MCP server.
     * 
     * @param McpServer $server The server
     * @return McpServer The enabled server
     */
    public function enableMcpServer(McpServer $server): McpServer
    {
        $server->setIsEnabled(true);
        $this->entityManager->persist($server);
        $this->entityManager->flush();

        $this->logger->info('Enabled MCP server', [
            'serverId' => $server->getId(),
            'identifier' => $server->getIdentifier(),
        ]);

        return $server;
    }

    /**
     * Disable an MCP server.
     * 
     * @param McpServer $server The server
     * @return McpServer The disabled server
     */
    public function disableMcpServer(McpServer $server): McpServer
    {
        $server->setIsEnabled(false);
        $server->setIsConnected(false);
        $this->entityManager->persist($server);
        $this->entityManager->flush();

        $this->logger->info('Disabled MCP server', [
            'serverId' => $server->getId(),
            'identifier' => $server->getIdentifier(),
        ]);

        return $server;
    }

    /**
     * Update an MCP server.
     * 
     * @param McpServer $server The server to update
     * @param array $updates Updates to apply
     * @return McpServer The updated server
     */
    public function updateMcpServer(McpServer $server, array $updates): McpServer
    {
        if (isset($updates['name'])) {
            $server->setName($updates['name']);
        }

        if (isset($updates['description'])) {
            $server->setDescription($updates['description']);
        }

        if (isset($updates['url'])) {
            $server->setUrl($updates['url']);
        }

        if (isset($updates['type'])) {
            $server->setType($updates['type']);
        }

        if (isset($updates['tools'])) {
            $server->setTools($updates['tools']);
        }

        if (isset($updates['resources'])) {
            $server->setResources($updates['resources']);
        }

        if (isset($updates['configuration'])) {
            $server->setConfiguration($updates['configuration']);
        }

        if (isset($updates['isEnabled'])) {
            $server->setIsEnabled($updates['isEnabled']);
        }

        $this->entityManager->persist($server);
        $this->entityManager->flush();

        return $server;
    }

    /**
     * Get MCP server statistics for a tenant.
     * 
     * @param Tenant $tenant The tenant
     * @return array Statistics
     */
    public function getStatistics(Tenant $tenant): array
    {
        return $this->mcpServerRepository->getStatistics($tenant->getId());
    }

    /**
     * Search MCP servers by name or description.
     * 
     * @param Tenant $tenant The tenant
     * @param string $query The search query
     * @return McpServer[]
     */
    public function search(Tenant $tenant, string $query): array
    {
        return $this->mcpServerRepository->search($tenant->getId(), $query);
    }

    /**
     * Get MCP server types.
     * 
     * @return array
     */
    public function getServerTypes(): array
    {
        return ['server', 'client', 'proxy'];
    }
}
