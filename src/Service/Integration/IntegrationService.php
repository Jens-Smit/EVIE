<?php

namespace App\Service\Integration;

use App\Entity\Integration\Integration;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\Organization;
use App\Repository\Integration\IntegrationRepository;
use App\Service\Security\SecretManager;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Ulid;

/**
 * IntegrationService manages third-party integrations.
 * 
 * Features:
 * - Integration registration and discovery
 * - Integration configuration
 * - Integration connection management
 * - Capability-based integration matching
 * - Built-in integration definitions
 */
class IntegrationService
{
    private const array BUILTIN_INTEGRATIONS = [
        // Microsoft Integrations
        [
            'type' => 'microsoft',
            'identifier' => 'microsoft_graph',
            'name' => 'Microsoft Graph API',
            'description' => 'Access Microsoft 365 services (email, calendar, contacts, files)',
            'baseUrl' => 'https://graph.microsoft.com/v1.0',
            'capabilities' => [
                'email:send',
                'email:read',
                'email:delete',
                'calendar:read',
                'calendar:write',
                'contacts:read',
                'contacts:write',
                'files:read',
                'files:write',
            ],
            'scopes' => [
                'User.Read',
                'Mail.Read',
                'Mail.Send',
                'Mail.ReadWrite',
                'Calendars.Read',
                'Calendars.ReadWrite',
                'Contacts.Read',
                'Contacts.ReadWrite',
                'Files.Read',
                'Files.ReadWrite',
            ],
            'requiredSecrets' => [
                'client_id',
                'client_secret',
                'tenant_id',
            ],
        ],
        [
            'type' => 'microsoft',
            'identifier' => 'microsoft_outlook',
            'name' => 'Microsoft Outlook',
            'description' => 'Access Outlook email services',
            'baseUrl' => 'https://outlook.office.com/api/v2.0',
            'capabilities' => [
                'email:send',
                'email:read',
                'email:delete',
            ],
            'scopes' => [
                'https://outlook.office.com/Mail.Read',
                'https://outlook.office.com/Mail.Send',
                'https://outlook.office.com/Mail.ReadWrite',
            ],
            'requiredSecrets' => [
                'client_id',
                'client_secret',
            ],
        ],

        // Google Integrations
        [
            'type' => 'google',
            'identifier' => 'google_workspace',
            'name' => 'Google Workspace',
            'description' => 'Access Google Workspace services (Gmail, Calendar, Drive, etc.)',
            'baseUrl' => 'https://www.googleapis.com',
            'capabilities' => [
                'email:send',
                'email:read',
                'email:delete',
                'calendar:read',
                'calendar:write',
                'drive:read',
                'drive:write',
                'contacts:read',
                'contacts:write',
            ],
            'scopes' => [
                'https://www.googleapis.com/auth/gmail.readonly',
                'https://www.googleapis.com/auth/gmail.send',
                'https://www.googleapis.com/auth/gmail.modify',
                'https://www.googleapis.com/auth/calendar.readonly',
                'https://www.googleapis.com/auth/calendar',
                'https://www.googleapis.com/auth/drive.readonly',
                'https://www.googleapis.com/auth/drive',
                'https://www.googleapis.com/auth/contacts.readonly',
                'https://www.googleapis.com/auth/contacts',
            ],
            'requiredSecrets' => [
                'client_id',
                'client_secret',
            ],
        ],
        [
            'type' => 'google',
            'identifier' => 'google_gmail',
            'name' => 'Google Gmail API',
            'description' => 'Access Gmail services',
            'baseUrl' => 'https://gmail.googleapis.com/v1',
            'capabilities' => [
                'email:send',
                'email:read',
                'email:delete',
            ],
            'scopes' => [
                'https://www.googleapis.com/auth/gmail.readonly',
                'https://www.googleapis.com/auth/gmail.send',
                'https://www.googleapis.com/auth/gmail.modify',
            ],
            'requiredSecrets' => [
                'client_id',
                'client_secret',
            ],
        ],
        [
            'type' => 'google',
            'identifier' => 'google_calendar',
            'name' => 'Google Calendar API',
            'description' => 'Access Google Calendar services',
            'baseUrl' => 'https://www.googleapis.com/calendar/v3',
            'capabilities' => [
                'calendar:read',
                'calendar:write',
            ],
            'scopes' => [
                'https://www.googleapis.com/auth/calendar.readonly',
                'https://www.googleapis.com/auth/calendar',
            ],
            'requiredSecrets' => [
                'client_id',
                'client_secret',
            ],
        ],
        [
            'type' => 'google',
            'identifier' => 'google_drive',
            'name' => 'Google Drive API',
            'description' => 'Access Google Drive services',
            'baseUrl' => 'https://www.googleapis.com/drive/v3',
            'capabilities' => [
                'drive:read',
                'drive:write',
                'files:read',
                'files:write',
            ],
            'scopes' => [
                'https://www.googleapis.com/auth/drive.readonly',
                'https://www.googleapis.com/auth/drive',
            ],
            'requiredSecrets' => [
                'client_id',
                'client_secret',
            ],
        ],

        // GitHub Integrations
        [
            'type' => 'github',
            'identifier' => 'github_api',
            'name' => 'GitHub API',
            'description' => 'Access GitHub repositories, issues, pull requests, etc.',
            'baseUrl' => 'https://api.github.com',
            'capabilities' => [
                'github:read',
                'github:write',
                'github:issues',
                'github:pull_requests',
                'github:repositories',
            ],
            'scopes' => [
                'repo',
                'read:org',
                'write:org',
                'admin:org',
            ],
            'requiredSecrets' => [
                'token',
            ],
        ],
        [
            'type' => 'github',
            'identifier' => 'github_enterprise',
            'name' => 'GitHub Enterprise',
            'description' => 'Access GitHub Enterprise services',
            'baseUrl' => null, // Will be configured per instance
            'capabilities' => [
                'github:read',
                'github:write',
                'github:issues',
                'github:pull_requests',
                'github:repositories',
            ],
            'scopes' => [],
            'requiredSecrets' => [
                'token',
                'base_url',
            ],
        ],

        // Browser/Web Integrations
        [
            'type' => 'browser',
            'identifier' => 'playwright',
            'name' => 'Playwright Browser',
            'description' => 'Browser automation for web scraping and testing',
            'baseUrl' => null, // Local service
            'capabilities' => [
                'web:browse',
                'web:scrape',
                'web:automate',
                'web:test',
            ],
            'scopes' => [],
            'requiredSecrets' => [],
        ],
        [
            'type' => 'browser',
            'identifier' => 'puppeteer',
            'name' => 'Puppeteer Browser',
            'description' => 'Headless Chrome/Chromium browser automation',
            'baseUrl' => null, // Local service
            'capabilities' => [
                'web:browse',
                'web:scrape',
                'web:automate',
            ],
            'scopes' => [],
            'requiredSecrets' => [],
        ],

        // Database Integrations
        [
            'type' => 'database',
            'identifier' => 'postgresql',
            'name' => 'PostgreSQL',
            'description' => 'PostgreSQL database connection',
            'baseUrl' => null,
            'capabilities' => [
                'database:query',
                'database:read',
                'database:write',
            ],
            'scopes' => [],
            'requiredSecrets' => [
                'host',
                'port',
                'database',
                'username',
                'password',
            ],
        ],
        [
            'type' => 'database',
            'identifier' => 'mysql',
            'name' => 'MySQL',
            'description' => 'MySQL database connection',
            'baseUrl' => null,
            'capabilities' => [
                'database:query',
                'database:read',
                'database:write',
            ],
            'scopes' => [],
            'requiredSecrets' => [
                'host',
                'port',
                'database',
                'username',
                'password',
            ],
        ],

        // Other Integrations
        [
            'type' => 'notion',
            'identifier' => 'notion_api',
            'name' => 'Notion API',
            'description' => 'Access Notion databases and pages',
            'baseUrl' => 'https://api.notion.com/v1',
            'capabilities' => [
                'notion:read',
                'notion:write',
                'notion:databases',
                'notion:pages',
            ],
            'scopes' => [],
            'requiredSecrets' => [
                'token',
            ],
        ],
        [
            'type' => 'slack',
            'identifier' => 'slack_api',
            'name' => 'Slack API',
            'description' => 'Access Slack workspace (messages, channels, users)',
            'baseUrl' => 'https://slack.com/api',
            'capabilities' => [
                'slack:read',
                'slack:write',
                'slack:messages',
                'slack:channels',
            ],
            'scopes' => [
                'channels:read',
                'channels:write',
                'chat:write',
                'users:read',
            ],
            'requiredSecrets' => [
                'bot_token',
                'signing_secret',
            ],
        ],
        [
            'type' => 'discord',
            'identifier' => 'discord_api',
            'name' => 'Discord API',
            'description' => 'Access Discord servers and channels',
            'baseUrl' => 'https://discord.com/api/v10',
            'capabilities' => [
                'discord:read',
                'discord:write',
                'discord:messages',
                'discord:channels',
            ],
            'scopes' => [
                'bot',
                'applications.commands',
            ],
            'requiredSecrets' => [
                'bot_token',
            ],
        ],
    ];

    public function __construct(
        private IntegrationRepository $integrationRepository,
        private SecretManager $secretManager,
        private McpRegistry $mcpRegistry,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Get all built-in integration definitions.
     * 
     * @return array
     */
    public function getBuiltinIntegrations(): array
    {
        return self::BUILTIN_INTEGRATIONS;
    }

    /**
     * Get a built-in integration by type and identifier.
     * 
     * @param string $type The integration type
     * @param string $identifier The integration identifier
     * @return array|null The integration definition or null
     */
    public function getBuiltinIntegration(string $type, string $identifier): ?array
    {
        foreach (self::BUILTIN_INTEGRATIONS as $integration) {
            if ($integration['type'] === $type && $integration['identifier'] === $identifier) {
                return $integration;
            }
        }
        return null;
    }

    /**
     * Get built-in integrations by type.
     * 
     * @param string $type The integration type
     * @return array
     */
    public function getBuiltinIntegrationsByType(string $type): array
    {
        $integrations = [];
        
        foreach (self::BUILTIN_INTEGRATIONS as $integration) {
            if ($integration['type'] === $type) {
                $integrations[] = $integration;
            }
        }
        
        return $integrations;
    }

    /**
     * Register a built-in integration for a tenant.
     * 
     * @param Tenant $tenant The tenant
     * @param string $type The integration type
     * @param string $identifier The integration identifier
     * @param Organization|null $organization The organization (optional)
     * @param array $overrides Override default configuration
     * @return Integration The registered integration
     */
    public function registerBuiltinIntegration(
        Tenant $tenant,
        string $type,
        string $identifier,
        ?Organization $organization = null,
        array $overrides = []
    ): Integration {
        $builtin = $this->getBuiltinIntegration($type, $identifier);
        
        if ($builtin === null) {
            throw new \InvalidArgumentException("Unknown integration: {$type}/{$identifier}");
        }

        // Check if integration already exists for this tenant
        $existing = $this->integrationRepository->findOneByTypeAndIdentifierAndTenant(
            $type,
            $identifier,
            $tenant->getId()
        );

        if ($existing !== null) {
            $this->logger->warning('Integration already registered, updating instead', [
                'type' => $type,
                'identifier' => $identifier,
                'tenantId' => $tenant->getId(),
            ]);
            
            return $this->updateIntegration($existing, $overrides);
        }

        // Create new integration
        $integration = new Integration();
        $integration->setType($type);
        $integration->setIdentifier($identifier);
        $integration->setName($overrides['name'] ?? $builtin['name']);
        $integration->setDescription($overrides['description'] ?? $builtin['description']);
        $integration->setBaseUrl($overrides['baseUrl'] ?? $builtin['baseUrl']);
        $integration->setCapabilities($overrides['capabilities'] ?? $builtin['capabilities']);
        $integration->setScopes($overrides['scopes'] ?? $builtin['scopes']);
        $integration->setConfiguration($overrides['configuration'] ?? []);
        $integration->setTenant($tenant);
        $integration->setOrganization($organization);
        $integration->setIsEnabled($overrides['isEnabled'] ?? true);
        $integration->setIsConfigured(false); // Not configured yet
        $integration->setIsConnected(false);

        $this->entityManager->persist($integration);
        $this->entityManager->flush();

        $this->logger->info('Registered built-in integration', [
            'type' => $type,
            'identifier' => $identifier,
            'tenantId' => $tenant->getId(),
            'organizationId' => $organization?->getId(),
        ]);

        return $integration;
    }

    /**
     * Register all built-in integrations for a tenant.
     * 
     * @param Tenant $tenant The tenant
     * @param Organization|null $organization The organization (optional)
     * @param array $exclude Types to exclude
     * @return Integration[] Registered integrations
     */
    public function registerAllBuiltinIntegrations(
        Tenant $tenant,
        ?Organization $organization = null,
        array $exclude = []
    ): array {
        $integrations = [];
        $types = array_unique(array_column(self::BUILTIN_INTEGRATIONS, 'type'));

        foreach ($types as $type) {
            if (in_array($type, $exclude, true)) {
                continue;
            }

            $typeIntegrations = $this->getBuiltinIntegrationsByType($type);
            
            foreach ($typeIntegrations as $builtin) {
                try {
                    $integration = $this->registerBuiltinIntegration(
                        $tenant,
                        $type,
                        $builtin['identifier'],
                        $organization
                    );
                    $integrations[] = $integration;
                } catch (\Exception $e) {
                    $this->logger->error('Failed to register integration', [
                        'type' => $type,
                        'identifier' => $builtin['identifier'],
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $integrations;
    }

    /**
     * Get all integrations for a tenant.
     * 
     * @param Tenant $tenant The tenant
     * @return Integration[]
     */
    public function getIntegrationsByTenant(Tenant $tenant): array
    {
        return $this->integrationRepository->findByTenant($tenant->getId());
    }

    /**
     * Get integrations by type for a tenant.
     * 
     * @param Tenant $tenant The tenant
     * @param string $type The integration type
     * @return Integration[]
     */
    public function getIntegrationsByType(Tenant $tenant, string $type): array
    {
        return $this->integrationRepository->findByType($tenant->getId(), $type);
    }

    /**
     * Get enabled integrations for a tenant.
     * 
     * @param Tenant $tenant The tenant
     * @return Integration[]
     */
    public function getEnabledIntegrationsByTenant(Tenant $tenant): array
    {
        return $this->integrationRepository->findEnabledByTenant($tenant->getId());
    }

    /**
     * Get ready integrations for a tenant.
     * 
     * @param Tenant $tenant The tenant
     * @return Integration[]
     */
    public function getReadyIntegrationsByTenant(Tenant $tenant): array
    {
        return $this->integrationRepository->findReadyByTenant($tenant->getId());
    }

    /**
     * Get an integration by type and identifier for a tenant.
     * 
     * @param Tenant $tenant The tenant
     * @param string $type The integration type
     * @param string $identifier The integration identifier
     * @return Integration|null
     */
    public function getIntegrationByTypeAndIdentifier(
        Tenant $tenant,
        string $type,
        string $identifier
    ): ?Integration {
        return $this->integrationRepository->findOneByTypeAndIdentifierAndTenant(
            $type,
            $identifier,
            $tenant->getId()
        );
    }

    /**
     * Find integrations that provide a specific capability.
     * 
     * @param Tenant $tenant The tenant
     * @param string $capability The capability
     * @return Integration[]
     */
    public function findByCapability(Tenant $tenant, string $capability): array
    {
        return $this->integrationRepository->findByCapability($tenant->getId(), $capability);
    }

    /**
     * Configure an integration.
     * 
     * @param Integration $integration The integration
     * @param array $configuration Configuration options
     * @param array $credentials Credentials
     * @return Integration The configured integration
     */
    public function configureIntegration(
        Integration $integration,
        array $configuration = [],
        array $credentials = []
    ): Integration {
        $currentConfig = $integration->getConfiguration();
        $newConfig = array_merge($currentConfig, $configuration);
        $integration->setConfiguration($newConfig);

        $currentCredentials = $integration->getCredentials() ?? [];
        $newCredentials = array_merge($currentCredentials, $credentials);
        $integration->setCredentials($newCredentials);

        // Store credentials in SecretManager
        $this->storeCredentialsInSecrets($integration, $credentials);

        // Mark as configured if we have required credentials
        if ($this->hasRequiredCredentials($integration)) {
            $integration->markAsConfigured();
        }

        $this->entityManager->persist($integration);
        $this->entityManager->flush();

        $this->logger->info('Configured integration', [
            'integrationId' => $integration->getId(),
            'type' => $integration->getType(),
            'identifier' => $integration->getIdentifier(),
        ]);

        return $integration;
    }

    /**
     * Store credentials in SecretManager.
     * 
     * @param Integration $integration The integration
     * @param array $credentials Credentials to store
     */
    private function storeCredentialsInSecrets(Integration $integration, array $credentials): void
    {
        $tenant = $integration->getTenant();
        $type = $integration->getType();
        $identifier = $integration->getIdentifier();

        foreach ($credentials as $key => $value) {
            $secretKey = sprintf('integration:%s:%s:%s', $type, $identifier, $key);
            
            // Check if secret already exists
            if ($this->secretManager->hasSecret($tenant->getId(), $secretKey)) {
                $secret = $this->secretManager->getSecret($tenant->getId(), $secretKey);
                $this->secretManager->updateSecret($secret, $value);
            } else {
                // Get the user from the integration or use a system user
                // For now, we'll use the first user of the tenant
                $users = $tenant->getUsers();
                $user = $users->first();
                
                if ($user !== null) {
                    $this->secretManager->createSecret(
                        $user,
                        $secretKey,
                        $value,
                        sprintf('Credential for %s integration: %s', $type, $key)
                    );
                }
            }
        }
    }

    /**
     * Check if an integration has all required credentials.
     * 
     * @param Integration $integration The integration
     * @return bool
     */
    public function hasRequiredCredentials(Integration $integration): bool
    {
        $builtin = $this->getBuiltinIntegration($integration->getType(), $integration->getIdentifier());
        
        if ($builtin === null) {
            return false;
        }

        $requiredSecrets = $builtin['requiredSecrets'] ?? [];
        $credentials = $integration->getCredentials() ?? [];

        foreach ($requiredSecrets as $secret) {
            if (!isset($credentials[$secret]) || empty($credentials[$secret])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Connect to an integration.
     * 
     * @param Integration $integration The integration
     * @return bool True if connection was successful
     */
    public function connect(Integration $integration): bool
    {
        // In a real implementation, you would:
        // 1. Make a test API call
        // 2. Verify the connection
        // 3. Handle authentication
        
        // For now, we'll simulate a connection
        // Check if we have required credentials
        if (!$this->hasRequiredCredentials($integration)) {
            $integration->setLastError('Missing required credentials');
            $integration->setLastErrorAt(new \DateTimeImmutable());
            $this->entityManager->persist($integration);
            $this->entityManager->flush();
            return false;
        }

        $integration->setIsConnected(true);
        $integration->setLastConnectedAt(new \DateTimeImmutable());
        $integration->setLastError(null);
        $integration->setLastErrorAt(null);
        $this->entityManager->persist($integration);
        $this->entityManager->flush();

        $this->logger->info('Connected to integration', [
            'integrationId' => $integration->getId(),
            'type' => $integration->getType(),
            'identifier' => $integration->getIdentifier(),
        ]);

        return true;
    }

    /**
     * Disconnect from an integration.
     * 
     * @param Integration $integration The integration
     * @return bool True if disconnection was successful
     */
    public function disconnect(Integration $integration): bool
    {
        $integration->setIsConnected(false);
        $this->entityManager->persist($integration);
        $this->entityManager->flush();

        $this->logger->info('Disconnected from integration', [
            'integrationId' => $integration->getId(),
            'type' => $integration->getType(),
            'identifier' => $integration->getIdentifier(),
        ]);

        return true;
    }

    /**
     * Enable an integration.
     * 
     * @param Integration $integration The integration
     * @return Integration The enabled integration
     */
    public function enableIntegration(Integration $integration): Integration
    {
        $integration->enable();
        $this->entityManager->persist($integration);
        $this->entityManager->flush();

        $this->logger->info('Enabled integration', [
            'integrationId' => $integration->getId(),
            'type' => $integration->getType(),
            'identifier' => $integration->getIdentifier(),
        ]);

        return $integration;
    }

    /**
     * Disable an integration.
     * 
     * @param Integration $integration The integration
     * @return Integration The disabled integration
     */
    public function disableIntegration(Integration $integration): Integration
    {
        $integration->disable();
        $integration->setIsConnected(false);
        $this->entityManager->persist($integration);
        $this->entityManager->flush();

        $this->logger->info('Disabled integration', [
            'integrationId' => $integration->getId(),
            'type' => $integration->getType(),
            'identifier' => $integration->getIdentifier(),
        ]);

        return $integration;
    }

    /**
     * Update an integration.
     * 
     * @param Integration $integration The integration to update
     * @param array $updates Updates to apply
     * @return Integration The updated integration
     */
    public function updateIntegration(Integration $integration, array $updates): Integration
    {
        if (isset($updates['name'])) {
            $integration->setName($updates['name']);
        }

        if (isset($updates['description'])) {
            $integration->setDescription($updates['description']);
        }

        if (isset($updates['baseUrl'])) {
            $integration->setBaseUrl($updates['baseUrl']);
        }

        if (isset($updates['configuration'])) {
            $currentConfig = $integration->getConfiguration();
            $newConfig = array_merge($currentConfig, $updates['configuration']);
            $integration->setConfiguration($newConfig);
        }

        if (isset($updates['capabilities'])) {
            $integration->setCapabilities($updates['capabilities']);
        }

        if (isset($updates['scopes'])) {
            $integration->setScopes($updates['scopes']);
        }

        if (isset($updates['isEnabled'])) {
            $integration->setIsEnabled($updates['isEnabled']);
        }

        $this->entityManager->persist($integration);
        $this->entityManager->flush();

        return $integration;
    }

    /**
     * Get integration statistics for a tenant.
     * 
     * @param Tenant $tenant The tenant
     * @return array Statistics
     */
    public function getStatistics(Tenant $tenant): array
    {
        return $this->integrationRepository->getStatistics($tenant->getId());
    }

    /**
     * Get available integration types.
     * 
     * @return array
     */
    public function getIntegrationTypes(): array
    {
        $types = [];
        
        foreach (self::BUILTIN_INTEGRATIONS as $integration) {
            if (!in_array($integration['type'], $types, true)) {
                $types[] = $integration['type'];
            }
        }

        return $types;
    }

    /**
     * Get capabilities for an integration.
     * 
     * @param Integration $integration The integration
     * @return array
     */
    public function getCapabilities(Integration $integration): array
    {
        return $integration->getCapabilities() ?? [];
    }

    /**
     * Check if an integration has a specific capability.
     * 
     * @param Integration $integration The integration
     * @param string $capability The capability
     * @return bool
     */
    public function hasCapability(Integration $integration, string $capability): bool
    {
        return $integration->hasCapability($capability);
    }

    /**
     * Get all capabilities across all integrations for a tenant.
     * 
     * @param Tenant $tenant The tenant
     * @return array All capabilities
     */
    public function getAllCapabilities(Tenant $tenant): array
    {
        $integrations = $this->getEnabledIntegrationsByTenant($tenant);
        $capabilities = [];

        foreach ($integrations as $integration) {
            $caps = $integration->getCapabilities() ?? [];
            foreach ($caps as $cap) {
                if (!in_array($cap, $capabilities, true)) {
                    $capabilities[] = $cap;
                }
            }
        }

        return $capabilities;
    }

    /**
     * Search integrations by name or description.
     * 
     * @param Tenant $tenant The tenant
     * @param string $query The search query
     * @return Integration[]
     */
    public function search(Tenant $tenant, string $query): array
    {
        return $this->integrationRepository->search($tenant->getId(), $query);
    }

    /**
     * Get unconfigured integrations for a tenant.
     * 
     * @param Tenant $tenant The tenant
     * @return Integration[]
     */
    public function getUnconfiguredIntegrations(Tenant $tenant): array
    {
        return $this->integrationRepository->findUnconfigured($tenant->getId());
    }

    /**
     * Get the MCP registry.
     * 
     * @return McpRegistry
     */
    public function getMcpRegistry(): McpRegistry
    {
        return $this->mcpRegistry;
    }
}
