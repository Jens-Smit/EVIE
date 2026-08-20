<?php

namespace App\Service\AI;

use App\Entity\AI\Capability;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\Organization;
use App\Repository\AI\CapabilityRepository;
use App\Service\Security\SecretManager;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Ulid;

/**
 * CapabilityRegistry manages the registry of available capabilities.
 * 
 * This service provides:
 * - Capability registration and discovery
 * - Capability installation and configuration
 * - Capability validation
 * - Capability resolution based on requirements
 * - Built-in capability definitions
 */
class CapabilityRegistry
{
    private const array BUILTIN_CAPABILITIES = [
        [
            'identifier' => 'conversation_management',
            'name' => 'Conversation Management',
            'description' => 'Manage conversations and message history',
            'category' => 'core',
            'provider' => 'evie',
            'version' => '1.0.0',
            'requiredSecrets' => [],
            'requiredIntegrations' => [],
            'requiredPermissions' => [],
            'configuration' => [],
        ],
        [
            'identifier' => 'llm_integration',
            'name' => 'LLM Integration',
            'description' => 'Integrate with Large Language Models',
            'category' => 'ai',
            'provider' => 'evie',
            'version' => '1.0.0',
            'requiredSecrets' => [
                'llm_api_key' => [
                    'description' => 'API key for LLM provider',
                    'required' => true,
                    'type' => 'string',
                ],
            ],
            'requiredIntegrations' => [],
            'requiredPermissions' => [],
            'configuration' => [
                'provider' => 'mistral',
                'model' => 'mistral-large',
            ],
        ],
        [
            'identifier' => 'email_management',
            'name' => 'Email Management',
            'description' => 'Send and receive emails',
            'category' => 'communication',
            'provider' => 'evie',
            'version' => '1.0.0',
            'requiredSecrets' => [
                'smtp_host' => [
                    'description' => 'SMTP server host',
                    'required' => true,
                    'type' => 'string',
                ],
                'smtp_port' => [
                    'description' => 'SMTP server port',
                    'required' => true,
                    'type' => 'integer',
                ],
                'smtp_username' => [
                    'description' => 'SMTP username',
                    'required' => true,
                    'type' => 'string',
                ],
                'smtp_password' => [
                    'description' => 'SMTP password',
                    'required' => true,
                    'type' => 'secret',
                ],
            ],
            'requiredIntegrations' => [],
            'requiredPermissions' => [
                'email:send' => [
                    'description' => 'Permission to send emails',
                    'required' => true,
                ],
            ],
            'configuration' => [
                'default_from' => '',
                'default_subject' => '',
            ],
        ],
        [
            'identifier' => 'file_management',
            'name' => 'File Management',
            'description' => 'Manage files and documents',
            'category' => 'storage',
            'provider' => 'evie',
            'version' => '1.0.0',
            'requiredSecrets' => [],
            'requiredIntegrations' => [],
            'requiredPermissions' => [
                'file:read' => [
                    'description' => 'Permission to read files',
                    'required' => true,
                ],
                'file:write' => [
                    'description' => 'Permission to write files',
                    'required' => true,
                ],
            ],
            'configuration' => [
                'storage_path' => '',
                'allowed_extensions' => ['txt', 'pdf', 'doc', 'docx'],
            ],
        ],
        [
            'identifier' => 'github_integration',
            'name' => 'GitHub Integration',
            'description' => 'Integrate with GitHub repositories',
            'category' => 'development',
            'provider' => 'github',
            'version' => '1.0.0',
            'requiredSecrets' => [
                'github_token' => [
                    'description' => 'GitHub personal access token',
                    'required' => true,
                    'type' => 'secret',
                ],
            ],
            'requiredIntegrations' => [],
            'requiredPermissions' => [
                'github:read' => [
                    'description' => 'Permission to read GitHub repositories',
                    'required' => true,
                ],
                'github:write' => [
                    'description' => 'Permission to write to GitHub repositories',
                    'required' => false,
                ],
            ],
            'configuration' => [
                'default_repository' => '',
                'default_branch' => 'main',
            ],
        ],
        [
            'identifier' => 'microsoft_graph',
            'name' => 'Microsoft Graph API',
            'description' => 'Integrate with Microsoft 365 services',
            'category' => 'productivity',
            'provider' => 'microsoft',
            'version' => '1.0.0',
            'requiredSecrets' => [
                'microsoft_client_id' => [
                    'description' => 'Microsoft App Client ID',
                    'required' => true,
                    'type' => 'string',
                ],
                'microsoft_client_secret' => [
                    'description' => 'Microsoft App Client Secret',
                    'required' => true,
                    'type' => 'secret',
                ],
                'microsoft_tenant_id' => [
                    'description' => 'Microsoft Tenant ID',
                    'required' => true,
                    'type' => 'string',
                ],
            ],
            'requiredIntegrations' => [],
            'requiredPermissions' => [
                'microsoft:email' => [
                    'description' => 'Permission to access Microsoft email',
                    'required' => false,
                ],
                'microsoft:calendar' => [
                    'description' => 'Permission to access Microsoft calendar',
                    'required' => false,
                ],
            ],
            'configuration' => [],
        ],
        [
            'identifier' => 'google_workspace',
            'name' => 'Google Workspace',
            'description' => 'Integrate with Google Workspace services',
            'category' => 'productivity',
            'provider' => 'google',
            'version' => '1.0.0',
            'requiredSecrets' => [
                'google_client_id' => [
                    'description' => 'Google OAuth Client ID',
                    'required' => true,
                    'type' => 'string',
                ],
                'google_client_secret' => [
                    'description' => 'Google OAuth Client Secret',
                    'required' => true,
                    'type' => 'secret',
                ],
            ],
            'requiredIntegrations' => [],
            'requiredPermissions' => [
                'google:email' => [
                    'description' => 'Permission to access Google email',
                    'required' => false,
                ],
                'google:calendar' => [
                    'description' => 'Permission to access Google calendar',
                    'required' => false,
                ],
                'google:drive' => [
                    'description' => 'Permission to access Google Drive',
                    'required' => false,
                ],
            ],
            'configuration' => [],
        ],
        [
            'identifier' => 'web_browsing',
            'name' => 'Web Browsing',
            'description' => 'Browse the web and retrieve information',
            'category' => 'research',
            'provider' => 'evie',
            'version' => '1.0.0',
            'requiredSecrets' => [],
            'requiredIntegrations' => [],
            'requiredPermissions' => [
                'web:browse' => [
                    'description' => 'Permission to browse the web',
                    'required' => true,
                ],
            ],
            'configuration' => [
                'user_agent' => '',
                'timeout' => 30,
            ],
        ],
        [
            'identifier' => 'database_query',
            'name' => 'Database Query',
            'description' => 'Query databases using natural language',
            'category' => 'data',
            'provider' => 'evie',
            'version' => '1.0.0',
            'requiredSecrets' => [
                'database_host' => [
                    'description' => 'Database host',
                    'required' => true,
                    'type' => 'string',
                ],
                'database_port' => [
                    'description' => 'Database port',
                    'required' => true,
                    'type' => 'integer',
                ],
                'database_name' => [
                    'description' => 'Database name',
                    'required' => true,
                    'type' => 'string',
                ],
                'database_username' => [
                    'description' => 'Database username',
                    'required' => true,
                    'type' => 'string',
                ],
                'database_password' => [
                    'description' => 'Database password',
                    'required' => true,
                    'type' => 'secret',
                ],
            ],
            'requiredIntegrations' => [],
            'requiredPermissions' => [
                'database:query' => [
                    'description' => 'Permission to query databases',
                    'required' => true,
                ],
            ],
            'configuration' => [
                'schema' => '',
                'read_only' => true,
            ],
        ],
    ];

    public function __construct(
        private CapabilityRepository $capabilityRepository,
        private EntityManagerInterface $entityManager,
        private SecretManager $secretManager,
        private LoggerInterface $logger,
        private string $defaultProvider = 'evie'
    ) {
    }

    /**
     * Get all built-in capability definitions.
     * 
     * @return array
     */
    public function getBuiltinCapabilities(): array
    {
        return self::BUILTIN_CAPABILITIES;
    }

    /**
     * Get a built-in capability by identifier.
     * 
     * @param string $identifier The capability identifier
     * @return array|null The capability definition or null
     */
    public function getBuiltinCapability(string $identifier): ?array
    {
        foreach (self::BUILTIN_CAPABILITIES as $capability) {
            if ($capability['identifier'] === $identifier) {
                return $capability;
            }
        }
        return null;
    }

    /**
     * Register a built-in capability for a tenant.
     * 
     * @param Tenant $tenant The tenant
     * @param string $identifier The capability identifier
     * @param Organization|null $organization The organization (optional)
     * @param array $overrides Override default configuration
     * @return Capability The registered capability
     */
    public function registerBuiltinCapability(
        Tenant $tenant,
        string $identifier,
        ?Organization $organization = null,
        array $overrides = []
    ): Capability {
        $builtin = $this->getBuiltinCapability($identifier);
        
        if ($builtin === null) {
            throw new \InvalidArgumentException("Unknown capability: {$identifier}");
        }

        // Check if capability already exists for this tenant
        $existing = $this->capabilityRepository->findOneByIdentifierAndTenant(
            $identifier,
            $tenant->getId()
        );

        if ($existing !== null) {
            $this->logger->warning('Capability already registered, updating instead', [
                'identifier' => $identifier,
                'tenantId' => $tenant->getId(),
            ]);
            
            return $this->updateCapability($existing, $overrides);
        }

        // Create new capability
        $capability = new Capability();
        $capability->setIdentifier($identifier);
        $capability->setName($overrides['name'] ?? $builtin['name']);
        $capability->setDescription($overrides['description'] ?? $builtin['description']);
        $capability->setCategory($overrides['category'] ?? $builtin['category']);
        $capability->setProvider($overrides['provider'] ?? $builtin['provider']);
        $capability->setVersion($overrides['version'] ?? $builtin['version']);
        $capability->setConfiguration($overrides['configuration'] ?? $builtin['configuration']);
        $capability->setRequiredSecrets($overrides['requiredSecrets'] ?? $builtin['requiredSecrets']);
        $capability->setRequiredIntegrations($overrides['requiredIntegrations'] ?? $builtin['requiredIntegrations']);
        $capability->setRequiredPermissions($overrides['requiredPermissions'] ?? $builtin['requiredPermissions']);
        $capability->setParameters($overrides['parameters'] ?? $builtin['parameters'] ?? null);
        $capability->setTenant($tenant);
        $capability->setOrganization($organization);
        $capability->setIsEnabled($overrides['isEnabled'] ?? true);
        $capability->setIsInstalled($overrides['isInstalled'] ?? false);
        $capability->setIsConfigured($overrides['isConfigured'] ?? false);

        $this->entityManager->persist($capability);
        $this->entityManager->flush();

        $this->logger->info('Registered built-in capability', [
            'identifier' => $identifier,
            'tenantId' => $tenant->getId(),
            'organizationId' => $organization?->getId(),
        ]);

        return $capability;
    }

    /**
     * Register all built-in capabilities for a tenant.
     * 
     * @param Tenant $tenant The tenant
     * @param Organization|null $organization The organization (optional)
     * @param array $exclude Identifiers to exclude
     * @return Capability[] Registered capabilities
     */
    public function registerAllBuiltinCapabilities(
        Tenant $tenant,
        ?Organization $organization = null,
        array $exclude = []
    ): array {
        $capabilities = [];
        
        foreach (self::BUILTIN_CAPABILITIES as $builtin) {
            $identifier = $builtin['identifier'];
            
            if (in_array($identifier, $exclude, true)) {
                continue;
            }

            try {
                $capability = $this->registerBuiltinCapability(
                    $tenant,
                    $identifier,
                    $organization
                );
                $capabilities[] = $capability;
            } catch (\Exception $e) {
                $this->logger->error('Failed to register capability', [
                    'identifier' => $identifier,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $capabilities;
    }

    /**
     * Get all capabilities for a tenant.
     * 
     * @param Tenant $tenant The tenant
     * @return Capability[]
     */
    public function getCapabilitiesByTenant(Tenant $tenant): array
    {
        return $this->capabilityRepository->findByTenant($tenant->getId());
    }

    /**
     * Get enabled capabilities for a tenant.
     * 
     * @param Tenant $tenant The tenant
     * @return Capability[]
     */
    public function getEnabledCapabilitiesByTenant(Tenant $tenant): array
    {
        return $this->capabilityRepository->findEnabledByTenant($tenant->getId());
    }

    /**
     * Get ready capabilities for a tenant.
     * 
     * @param Tenant $tenant The tenant
     * @return Capability[]
     */
    public function getReadyCapabilitiesByTenant(Tenant $tenant): array
    {
        return $this->capabilityRepository->findReadyByTenant($tenant->getId());
    }

    /**
     * Get capabilities by category for a tenant.
     * 
     * @param Tenant $tenant The tenant
     * @param string $category The category
     * @return Capability[]
     */
    public function getCapabilitiesByCategory(Tenant $tenant, string $category): array
    {
        return $this->capabilityRepository->findByCategory($tenant->getId(), $category);
    }

    /**
     * Get a capability by identifier for a tenant.
     * 
     * @param Tenant $tenant The tenant
     * @param string $identifier The capability identifier
     * @return Capability|null
     */
    public function getCapabilityByIdentifier(Tenant $tenant, string $identifier): ?Capability
    {
        return $this->capabilityRepository->findOneByIdentifierAndTenant(
            $identifier,
            $tenant->getId()
        );
    }

    /**
     * Find capabilities that can be used with the given secrets and integrations.
     * 
     * @param Tenant $tenant The tenant
     * @param array $availableSecrets Available secret keys
     * @param array $availableIntegrations Available integrations
     * @param array $availablePermissions Available permissions
     * @return Capability[]
     */
    public function findUsableCapabilities(
        Tenant $tenant,
        array $availableSecrets = [],
        array $availableIntegrations = [],
        array $availablePermissions = []
    ): array {
        $capabilities = $this->getEnabledCapabilitiesByTenant($tenant);
        $usable = [];

        foreach ($capabilities as $capability) {
            // Check if all required secrets are available
            $hasSecrets = $capability->hasRequiredSecrets($availableSecrets);
            
            // Check if all required integrations are available
            $hasIntegrations = $capability->hasRequiredIntegrations($availableIntegrations);
            
            // Check if all required permissions are granted
            $hasPermissions = $capability->hasRequiredPermissions($availablePermissions);

            if ($hasSecrets && $hasIntegrations && $hasPermissions) {
                $usable[] = $capability;
            }
        }

        return $usable;
    }

    /**
     * Install a capability for a tenant.
     * 
     * @param Tenant $tenant The tenant
     * @param string $identifier The capability identifier
     * @param array $configuration Configuration options
     * @return Capability The installed capability
     */
    public function installCapability(
        Tenant $tenant,
        string $identifier,
        array $configuration = []
    ): Capability {
        $capability = $this->getCapabilityByIdentifier($tenant, $identifier);
        
        if ($capability === null) {
            // Register the capability first
            $capability = $this->registerBuiltinCapability($tenant, $identifier);
        }

        // Update configuration
        $currentConfig = $capability->getConfiguration();
        $newConfig = array_merge($currentConfig, $configuration);
        $capability->setConfiguration($newConfig);

        // Mark as installed
        $capability->markAsInstalled();

        // Check if all required secrets are configured
        if ($this->hasRequiredSecrets($capability)) {
            $capability->markAsConfigured();
        }

        $this->entityManager->persist($capability);
        $this->entityManager->flush();

        $this->logger->info('Installed capability', [
            'identifier' => $identifier,
            'tenantId' => $tenant->getId(),
        ]);

        return $capability;
    }

    /**
     * Uninstall a capability for a tenant.
     * 
     * @param Tenant $tenant The tenant
     * @param string $identifier The capability identifier
     * @return bool True if capability was uninstalled
     */
    public function uninstallCapability(Tenant $tenant, string $identifier): bool
    {
        $capability = $this->getCapabilityByIdentifier($tenant, $identifier);
        
        if ($capability === null) {
            return false;
        }

        $capability->markAsUninstalled();
        $capability->markAsUnconfigured();

        $this->entityManager->persist($capability);
        $this->entityManager->flush();

        $this->logger->info('Uninstalled capability', [
            'identifier' => $identifier,
            'tenantId' => $tenant->getId(),
        ]);

        return true;
    }

    /**
     * Configure a capability.
     * 
     * @param Capability $capability The capability
     * @param array $configuration Configuration options
     * @return Capability The configured capability
     */
    public function configureCapability(Capability $capability, array $configuration): Capability
    {
        $currentConfig = $capability->getConfiguration();
        $newConfig = array_merge($currentConfig, $configuration);
        $capability->setConfiguration($newConfig);

        // Check if all required secrets are configured
        if ($this->hasRequiredSecrets($capability)) {
            $capability->markAsConfigured();
        }

        $this->entityManager->persist($capability);
        $this->entityManager->flush();

        $this->logger->info('Configured capability', [
            'identifier' => $capability->getIdentifier(),
            'tenantId' => $capability->getTenantId(),
        ]);

        return $capability;
    }

    /**
     * Enable a capability.
     * 
     * @param Capability $capability The capability
     * @return Capability The enabled capability
     */
    public function enableCapability(Capability $capability): Capability
    {
        $capability->enable();
        $this->entityManager->persist($capability);
        $this->entityManager->flush();

        $this->logger->info('Enabled capability', [
            'identifier' => $capability->getIdentifier(),
            'tenantId' => $capability->getTenantId(),
        ]);

        return $capability;
    }

    /**
     * Disable a capability.
     * 
     * @param Capability $capability The capability
     * @return Capability The disabled capability
     */
    public function disableCapability(Capability $capability): Capability
    {
        $capability->disable();
        $this->entityManager->persist($capability);
        $this->entityManager->flush();

        $this->logger->info('Disabled capability', [
            'identifier' => $capability->getIdentifier(),
            'tenantId' => $capability->getTenantId(),
        ]);

        return $capability;
    }

    /**
     * Update a capability.
     * 
     * @param Capability $capability The capability to update
     * @param array $updates Updates to apply
     * @return Capability The updated capability
     */
    public function updateCapability(Capability $capability, array $updates): Capability
    {
        if (isset($updates['name'])) {
            $capability->setName($updates['name']);
        }

        if (isset($updates['description'])) {
            $capability->setDescription($updates['description']);
        }

        if (isset($updates['category'])) {
            $capability->setCategory($updates['category']);
        }

        if (isset($updates['provider'])) {
            $capability->setProvider($updates['provider']);
        }

        if (isset($updates['version'])) {
            $capability->setVersion($updates['version']);
        }

        if (isset($updates['configuration'])) {
            $capability->setConfiguration($updates['configuration']);
        }

        if (isset($updates['requiredSecrets'])) {
            $capability->setRequiredSecrets($updates['requiredSecrets']);
        }

        if (isset($updates['requiredIntegrations'])) {
            $capability->setRequiredIntegrations($updates['requiredIntegrations']);
        }

        if (isset($updates['requiredPermissions'])) {
            $capability->setRequiredPermissions($updates['requiredPermissions']);
        }

        if (isset($updates['parameters'])) {
            $capability->setParameters($updates['parameters']);
        }

        if (isset($updates['isEnabled'])) {
            $capability->setIsEnabled($updates['isEnabled']);
        }

        if (isset($updates['isInstalled'])) {
            $capability->setIsInstalled($updates['isInstalled']);
        }

        if (isset($updates['isConfigured'])) {
            $capability->setIsConfigured($updates['isConfigured']);
        }

        if (isset($updates['metadata'])) {
            $capability->setMetadata($updates['metadata']);
        }

        $this->entityManager->persist($capability);
        $this->entityManager->flush();

        return $capability;
    }

    /**
     * Check if a capability has all required secrets configured.
     * 
     * @param Capability $capability The capability
     * @return bool
     */
    public function hasRequiredSecrets(Capability $capability): bool
    {
        $requiredSecrets = $capability->getRequiredSecrets();
        
        foreach ($requiredSecrets as $secretKey => $config) {
            if ($config['required'] && !$this->secretManager->hasSecret($capability->getTenantId(), $secretKey)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get missing required secrets for a capability.
     * 
     * @param Capability $capability The capability
     * @return array Missing secret keys
     */
    public function getMissingSecrets(Capability $capability): array
    {
        $requiredSecrets = $capability->getRequiredSecrets();
        $missing = [];

        foreach ($requiredSecrets as $secretKey => $config) {
            if ($config['required'] && !$this->secretManager->hasSecret($capability->getTenantId(), $secretKey)) {
                $missing[] = $secretKey;
            }
        }

        return $missing;
    }

    /**
     * Get capability categories.
     * 
     * @return array
     */
    public function getCategories(): array
    {
        $categories = [];
        
        foreach (self::BUILTIN_CAPABILITIES as $capability) {
            $category = $capability['category'];
            if (!in_array($category, $categories, true)) {
                $categories[] = $category;
            }
        }

        return $categories;
    }

    /**
     * Get capability statistics for a tenant.
     * 
     * @param Tenant $tenant The tenant
     * @return array Statistics
     */
    public function getStatistics(Tenant $tenant): array
    {
        return $this->capabilityRepository->getStatistics($tenant->getId());
    }

    /**
     * Search capabilities by name or description.
     * 
     * @param Tenant $tenant The tenant
     * @param string $query The search query
     * @return Capability[]
     */
    public function search(Tenant $tenant, string $query): array
    {
        return $this->capabilityRepository->search($tenant->getId(), $query);
    }

    /**
     * Get all available capability identifiers.
     * 
     * @return array
     */
    public function getAvailableIdentifiers(): array
    {
        $identifiers = [];
        
        foreach (self::BUILTIN_CAPABILITIES as $capability) {
            $identifiers[] = $capability['identifier'];
        }

        return $identifiers;
    }

    /**
     * Validate a capability configuration.
     * 
     * @param Capability $capability The capability
     * @param array $configuration The configuration to validate
     * @return array Validation errors
     */
    public function validateConfiguration(Capability $capability, array $configuration): array
    {
        $errors = [];
        $requiredSecrets = $capability->getRequiredSecrets();

        foreach ($requiredSecrets as $secretKey => $config) {
            if ($config['required'] && !isset($configuration[$secretKey])) {
                $errors[$secretKey] = $config['description'] . ' is required';
            }
        }

        return $errors;
    }
}
