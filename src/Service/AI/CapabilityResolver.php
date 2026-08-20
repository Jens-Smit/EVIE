<?php

namespace App\Service\AI;

use App\Entity\AI\Capability;
use App\Entity\Tenant\Tenant;
use App\Service\Automation\SchedulerService;
use App\Service\Security\SecretManager;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * CapabilityResolver resolves capabilities based on requirements.
 * 
 * This service provides:
 * - Capability resolution by identifier
 * - Capability resolution by requirements (secrets, integrations, permissions)
 * - Capability matching for agent execution
 * - Capability validation
 * - Capability installation recommendations
 */
class CapabilityResolver
{
    public function __construct(
        private CapabilityRegistry $capabilityRegistry,
        private SecretManager $secretManager,
        private SchedulerService $schedulerService,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Resolve a capability by identifier for a tenant.
     * 
     * @param Tenant $tenant The tenant
     * @param string $identifier The capability identifier
     * @param bool $autoInstall Whether to auto-install if not found
     * @return Capability|null The resolved capability or null
     */
    public function resolveByIdentifier(
        Tenant $tenant,
        string $identifier,
        bool $autoInstall = false
    ): ?Capability {
        $capability = $this->capabilityRegistry->getCapabilityByIdentifier($tenant, $identifier);
        
        if ($capability === null && $autoInstall) {
            $capability = $this->capabilityRegistry->installCapability($tenant, $identifier);
        }

        return $capability;
    }

    /**
     * Resolve capabilities by required secrets.
     * 
     * @param Tenant $tenant The tenant
     * @param string|array $requiredSecrets Required secret key(s)
     * @return Capability[]
     */
    public function resolveByRequiredSecrets(Tenant $tenant, $requiredSecrets): array
    {
        if (is_string($requiredSecrets)) {
            $requiredSecrets = [$requiredSecrets];
        }

        $capabilities = [];
        
        foreach ($requiredSecrets as $secretKey) {
            $caps = $this->capabilityRegistry->getCapabilitiesByTenant($tenant);
            
            foreach ($caps as $cap) {
                if ($cap->requiresSecret($secretKey) && !in_array($cap, $capabilities, true)) {
                    $capabilities[] = $cap;
                }
            }
        }

        return $capabilities;
    }

    /**
     * Resolve capabilities by required integrations.
     * 
     * @param Tenant $tenant The tenant
     * @param string|array $requiredIntegrations Required integration(s)
     * @return Capability[]
     */
    public function resolveByRequiredIntegrations(Tenant $tenant, $requiredIntegrations): array
    {
        if (is_string($requiredIntegrations)) {
            $requiredIntegrations = [$requiredIntegrations];
        }

        $capabilities = [];
        
        foreach ($requiredIntegrations as $integration) {
            $caps = $this->capabilityRegistry->getCapabilitiesByTenant($tenant);
            
            foreach ($caps as $cap) {
                if ($cap->requiresIntegration($integration) && !in_array($cap, $capabilities, true)) {
                    $capabilities[] = $cap;
                }
            }
        }

        return $capabilities;
    }

    /**
     * Resolve capabilities by required permissions.
     * 
     * @param Tenant $tenant The tenant
     * @param string|array $requiredPermissions Required permission(s)
     * @return Capability[]
     */
    public function resolveByRequiredPermissions(Tenant $tenant, $requiredPermissions): array
    {
        if (is_string($requiredPermissions)) {
            $requiredPermissions = [$requiredPermissions];
        }

        $capabilities = [];
        
        foreach ($requiredPermissions as $permission) {
            $caps = $this->capabilityRegistry->getCapabilitiesByTenant($tenant);
            
            foreach ($caps as $cap) {
                if ($cap->requiresPermission($permission) && !in_array($cap, $capabilities, true)) {
                    $capabilities[] = $cap;
                }
            }
        }

        return $capabilities;
    }

    /**
     * Resolve capabilities that can be used with the given requirements.
     * 
     * @param Tenant $tenant The tenant
     * @param array $requirements Requirements (secrets, integrations, permissions)
     * @return Capability[]
     */
    public function resolveByRequirements(Tenant $tenant, array $requirements): array
    {
        $capabilities = $this->capabilityRegistry->getEnabledCapabilitiesByTenant($tenant);
        $resolved = [];

        foreach ($capabilities as $capability) {
            // Check secrets
            $secretsMatch = true;
            if (isset($requirements['secrets'])) {
                foreach ($requirements['secrets'] as $secretKey) {
                    if (!$capability->requiresSecret($secretKey)) {
                        $secretsMatch = false;
                        break;
                    }
                }
            }

            // Check integrations
            $integrationsMatch = true;
            if (isset($requirements['integrations'])) {
                foreach ($requirements['integrations'] as $integration) {
                    if (!$capability->requiresIntegration($integration)) {
                        $integrationsMatch = false;
                        break;
                    }
                }
            }

            // Check permissions
            $permissionsMatch = true;
            if (isset($requirements['permissions'])) {
                foreach ($requirements['permissions'] as $permission) {
                    if (!$capability->requiresPermission($permission)) {
                        $permissionsMatch = false;
                        break;
                    }
                }
            }

            if ($secretsMatch && $integrationsMatch && $permissionsMatch) {
                $resolved[] = $capability;
            }
        }

        return $resolved;
    }

    /**
     * Resolve capabilities for an agent execution.
     * 
     * @param Tenant $tenant The tenant
     * @param string $agentName The agent name
     * @param array $agentRequirements Agent requirements
     * @return array Resolved capabilities and information
     */
    public function resolveForAgent(Tenant $tenant, string $agentName, array $agentRequirements = []): array
    {
        $result = [
            'agentName' => $agentName,
            'capabilities' => [],
            'missingSecrets' => [],
            'missingIntegrations' => [],
            'missingPermissions' => [],
            'isReady' => true,
        ];

        // Get capabilities that match the agent requirements
        $capabilities = $this->resolveByRequirements($tenant, $agentRequirements);

        foreach ($capabilities as $capability) {
            // Check if capability is ready
            if ($capability->isReady()) {
                $result['capabilities'][] = $capability;
            } else {
                // Check what's missing
                $missing = $this->getMissingRequirements($capability);
                
                $result['missingSecrets'] = array_merge($result['missingSecrets'], $missing['secrets']);
                $result['missingIntegrations'] = array_merge($result['missingIntegrations'], $missing['integrations']);
                $result['missingPermissions'] = array_merge($result['missingPermissions'], $missing['permissions']);
                $result['isReady'] = false;
            }
        }

        // Remove duplicates
        $result['missingSecrets'] = array_unique($result['missingSecrets']);
        $result['missingIntegrations'] = array_unique($result['missingIntegrations']);
        $result['missingPermissions'] = array_unique($result['missingPermissions']);

        return $result;
    }

    /**
     * Get missing requirements for a capability.
     * 
     * @param Capability $capability The capability
     * @return array Missing requirements
     */
    public function getMissingRequirements(Capability $capability): array
    {
        $missing = [
            'secrets' => [],
            'integrations' => [],
            'permissions' => [],
        ];

        // Check secrets
        $requiredSecrets = $capability->getRequiredSecrets();
        foreach ($requiredSecrets as $secretKey => $config) {
            if ($config['required'] && !$this->secretManager->hasSecret($capability->getTenantId(), $secretKey)) {
                $missing['secrets'][] = $secretKey;
            }
        }

        // For integrations and permissions, we would check against available
        // integrations and permissions in a real implementation
        // For now, we'll just return empty arrays

        return $missing;
    }

    /**
     * Validate that a tenant has all required capabilities for an agent.
     * 
     * @param Tenant $tenant The tenant
     * @param string $agentName The agent name
     * @param array $agentRequirements Agent requirements
     * @return array Validation result
     */
    public function validateAgentRequirements(
        Tenant $tenant,
        string $agentName,
        array $agentRequirements = []
    ): array {
        $result = $this->resolveForAgent($tenant, $agentName, $agentRequirements);
        
        $result['canExecute'] = $result['isReady'] && !empty($result['capabilities']);
        
        return $result;
    }

    /**
     * Get recommendations for missing capabilities.
     * 
     * @param Tenant $tenant The tenant
     * @param array $missingRequirements Missing requirements
     * @return array Recommendations
     */
    public function getRecommendations(Tenant $tenant, array $missingRequirements): array
    {
        $recommendations = [];

        // Recommend capabilities that provide missing secrets
        if (!empty($missingRequirements['secrets'])) {
            foreach ($missingRequirements['secrets'] as $secretKey) {
                $caps = $this->capabilityRegistry->findByRequiredSecret($tenant->getId(), $secretKey);
                
                foreach ($caps as $cap) {
                    if (!$cap->isInstalled()) {
                        $recommendations[] = [
                            'type' => 'capability',
                            'action' => 'install',
                            'capability' => $cap->getIdentifier(),
                            'reason' => "Provides required secret: {$secretKey}",
                        ];
                    } elseif (!$cap->isConfigured()) {
                        $recommendations[] = [
                            'type' => 'capability',
                            'action' => 'configure',
                            'capability' => $cap->getIdentifier(),
                            'reason' => "Needs configuration for secret: {$secretKey}",
                        ];
                    }
                }
            }
        }

        // Recommend capabilities that provide missing integrations
        if (!empty($missingRequirements['integrations'])) {
            foreach ($missingRequirements['integrations'] as $integration) {
                $caps = $this->capabilityRegistry->findByRequiredIntegration($tenant->getId(), $integration);
                
                foreach ($caps as $cap) {
                    if (!$cap->isInstalled()) {
                        $recommendations[] = [
                            'type' => 'capability',
                            'action' => 'install',
                            'capability' => $cap->getIdentifier(),
                            'reason' => "Provides required integration: {$integration}",
                        ];
                    }
                }
            }
        }

        return $recommendations;
    }

    /**
     * Get all available capabilities for a tenant.
     * 
     * @param Tenant $tenant The tenant
     * @return array Available capabilities grouped by category
     */
    public function getAvailableCapabilities(Tenant $tenant): array
    {
        $capabilities = $this->capabilityRegistry->getCapabilitiesByTenant($tenant);
        $grouped = [];

        foreach ($capabilities as $capability) {
            $category = $capability->getCategory();
            if (!isset($grouped[$category])) {
                $grouped[$category] = [];
            }
            $grouped[$category][] = $capability;
        }

        return $grouped;
    }

    /**
     * Get ready capabilities for a tenant.
     * 
     * @param Tenant $tenant The tenant
     * @return array Ready capabilities grouped by category
     */
    public function getReadyCapabilities(Tenant $tenant): array
    {
        $capabilities = $this->capabilityRegistry->getReadyCapabilitiesByTenant($tenant);
        $grouped = [];

        foreach ($capabilities as $capability) {
            $category = $capability->getCategory();
            if (!isset($grouped[$category])) {
                $grouped[$category] = [];
            }
            $grouped[$category][] = $capability;
        }

        return $grouped;
    }

    /**
     * Get capabilities that are missing requirements.
     * 
     * @param Tenant $tenant The tenant
     * @return array Capabilities with missing requirements
     */
    public function getCapabilitiesWithMissingRequirements(Tenant $tenant): array
    {
        $capabilities = $this->capabilityRegistry->getEnabledCapabilitiesByTenant($tenant);
        $result = [];

        foreach ($capabilities as $capability) {
            if (!$capability->isReady()) {
                $missing = $this->getMissingRequirements($capability);
                
                if (!empty($missing['secrets']) || !empty($missing['integrations']) || !empty($missing['permissions'])) {
                    $result[] = [
                        'capability' => $capability,
                        'missing' => $missing,
                    ];
                }
            }
        }

        return $result;
    }

    /**
     * Get a summary of capability status for a tenant.
     * 
     * @param Tenant $tenant The tenant
     * @return array Summary
     */
    public function getCapabilityStatusSummary(Tenant $tenant): array
    {
        $stats = $this->capabilityRegistry->getStatistics($tenant);
        
        $missingRequirements = $this->getCapabilitiesWithMissingRequirements($tenant);
        
        $missingSecrets = [];
        $missingIntegrations = [];
        $missingPermissions = [];

        foreach ($missingRequirements as $item) {
            $missingSecrets = array_merge($missingSecrets, $item['missing']['secrets']);
            $missingIntegrations = array_merge($missingIntegrations, $item['missing']['integrations']);
            $missingPermissions = array_merge($missingPermissions, $item['missing']['permissions']);
        }

        return [
            'total' => $stats['total'] ?? 0,
            'enabled' => $stats['enabled'] ?? 0,
            'installed' => $stats['installed'] ?? 0,
            'configured' => $stats['configured'] ?? 0,
            'ready' => $stats['ready'] ?? 0,
            'missingSecrets' => array_unique($missingSecrets),
            'missingIntegrations' => array_unique($missingIntegrations),
            'missingPermissions' => array_unique($missingPermissions),
            'isFullyConfigured' => empty($missingSecrets) && empty($missingIntegrations) && empty($missingPermissions),
        ];
    }

    /**
     * Resolve a capability by name (alias for identifier).
     * 
     * @param Tenant $tenant The tenant
     * @param string $name The capability name or identifier
     * @param bool $autoInstall Whether to auto-install if not found
     * @return Capability|null The resolved capability or null
     */
    public function resolveByName(Tenant $tenant, string $name, bool $autoInstall = false): ?Capability
    {
        // First try by identifier
        $capability = $this->resolveByIdentifier($tenant, $name, $autoInstall);
        
        if ($capability !== null) {
            return $capability;
        }

        // If not found by identifier, try by name
        $capabilities = $this->capabilityRegistry->getCapabilitiesByTenant($tenant);
        
        foreach ($capabilities as $cap) {
            if (strtolower($cap->getName()) === strtolower($name)) {
                return $cap;
            }
        }

        return null;
    }
}
