<?php

namespace App\Service\Integration;

use App\Entity\Integration\Integration;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\User;
use Psr\Log\LoggerInterface;

/**
 * IntegrationManager is a facade service for managing all integrations.
 * 
 * This service provides a unified interface for:
 * - Managing integrations (register, configure, connect)
 * - Accessing specific integration services
 * - Checking integration status
 * - Getting available capabilities
 */
class IntegrationManager
{
    public function __construct(
        private IntegrationService $integrationService,
        private MicrosoftIntegrationService $microsoftIntegration,
        private GoogleIntegrationService $googleIntegration,
        private GitHubIntegrationService $githubIntegration,
        private BrowserIntegrationService $browserIntegration,
        private LoggerInterface $logger
    ) {
    }

    // ==================== Integration Management ====================

    /**
     * Register a built-in integration for a tenant.
     * 
     * @param Tenant $tenant The tenant
     * @param string $type The integration type
     * @param string $identifier The integration identifier
     * @param array $overrides Override default configuration
     * @return Integration The registered integration
     */
    public function registerIntegration(
        Tenant $tenant,
        string $type,
        string $identifier,
        array $overrides = []
    ): Integration {
        return $this->integrationService->registerBuiltinIntegration(
            $tenant,
            $type,
            $identifier,
            null,
            $overrides
        );
    }

    /**
     * Register all built-in integrations for a tenant.
     * 
     * @param Tenant $tenant The tenant
     * @param array $exclude Types to exclude
     * @return Integration[] Registered integrations
     */
    public function registerAllIntegrations(
        Tenant $tenant,
        array $exclude = []
    ): array {
        return $this->integrationService->registerAllBuiltinIntegrations(
            $tenant,
            null,
            $exclude
        );
    }

    /**
     * Get an integration by type and identifier for a tenant.
     * 
     * @param Tenant $tenant The tenant
     * @param string $type The integration type
     * @param string $identifier The integration identifier
     * @return Integration|null
     */
    public function getIntegration(
        Tenant $tenant,
        string $type,
        string $identifier
    ): ?Integration {
        return $this->integrationService->getIntegrationByTypeAndIdentifier(
            $tenant,
            $type,
            $identifier
        );
    }

    /**
     * Get all integrations for a tenant.
     * 
     * @param Tenant $tenant The tenant
     * @return Integration[]
     */
    public function getIntegrations(Tenant $tenant): array
    {
        return $this->integrationService->getIntegrationsByTenant($tenant);
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
        return $this->integrationService->getIntegrationsByType($tenant, $type);
    }

    /**
     * Get enabled integrations for a tenant.
     * 
     * @param Tenant $tenant The tenant
     * @return Integration[]
     */
    public function getEnabledIntegrations(Tenant $tenant): array
    {
        return $this->integrationService->getEnabledIntegrationsByTenant($tenant);
    }

    /**
     * Get ready integrations for a tenant.
     * 
     * @param Tenant $tenant The tenant
     * @return Integration[]
     */
    public function getReadyIntegrations(Tenant $tenant): array
    {
        return $this->integrationService->getReadyIntegrationsByTenant($tenant);
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
        return $this->integrationService->configureIntegration(
            $integration,
            $configuration,
            $credentials
        );
    }

    /**
     * Connect to an integration.
     * 
     * @param Integration $integration The integration
     * @return bool True if connection was successful
     */
    public function connect(Integration $integration): bool
    {
        return $this->integrationService->connect($integration);
    }

    /**
     * Disconnect from an integration.
     * 
     * @param Integration $integration The integration
     * @return bool True if disconnection was successful
     */
    public function disconnect(Integration $integration): bool
    {
        return $this->integrationService->disconnect($integration);
    }

    /**
     * Enable an integration.
     * 
     * @param Integration $integration The integration
     * @return Integration The enabled integration
     */
    public function enableIntegration(Integration $integration): Integration
    {
        return $this->integrationService->enableIntegration($integration);
    }

    /**
     * Disable an integration.
     * 
     * @param Integration $integration The integration
     * @return Integration The disabled integration
     */
    public function disableIntegration(Integration $integration): Integration
    {
        return $this->integrationService->disableIntegration($integration);
    }

    /**
     * Get integration statistics for a tenant.
     * 
     * @param Tenant $tenant The tenant
     * @return array Statistics
     */
    public function getStatistics(Tenant $tenant): array
    {
        return $this->integrationService->getStatistics($tenant);
    }

    /**
     * Get available integration types.
     * 
     * @return array
     */
    public function getIntegrationTypes(): array
    {
        return $this->integrationService->getIntegrationTypes();
    }

    /**
     * Get all capabilities across all integrations for a tenant.
     * 
     * @param Tenant $tenant The tenant
     * @return array All capabilities
     */
    public function getAllCapabilities(Tenant $tenant): array
    {
        return $this->integrationService->getAllCapabilities($tenant);
    }

    /**
     * Check if a tenant has a specific capability.
     * 
     * @param Tenant $tenant The tenant
     * @param string $capability The capability
     * @return bool
     */
    public function hasCapability(Tenant $tenant, string $capability): bool
    {
        $integrations = $this->getEnabledIntegrations($tenant);
        
        foreach ($integrations as $integration) {
            if ($integration->hasCapability($capability)) {
                return true;
            }
        }

        return false;
    }

    // ==================== Microsoft Integration ====================

    /**
     * Get the Microsoft integration service.
     * 
     * @return MicrosoftIntegrationService
     */
    public function microsoft(): MicrosoftIntegrationService
    {
        return $this->microsoftIntegration;
    }

    /**
     * Check if Microsoft integration is ready for a tenant.
     * 
     * @param Tenant $tenant The tenant
     * @return bool
     */
    public function isMicrosoftReady(Tenant $tenant): bool
    {
        return $this->microsoftIntegration->isReady($tenant);
    }

    // ==================== Google Integration ====================

    /**
     * Get the Google integration service.
     * 
     * @return GoogleIntegrationService
     */
    public function google(): GoogleIntegrationService
    {
        return $this->googleIntegration;
    }

    /**
     * Check if Google integration is ready for a tenant.
     * 
     * @param Tenant $tenant The tenant
     * @return bool
     */
    public function isGoogleReady(Tenant $tenant): bool
    {
        return $this->googleIntegration->isReady($tenant);
    }

    // ==================== GitHub Integration ====================

    /**
     * Get the GitHub integration service.
     * 
     * @return GitHubIntegrationService
     */
    public function github(): GitHubIntegrationService
    {
        return $this->githubIntegration;
    }

    /**
     * Check if GitHub integration is ready for a tenant.
     * 
     * @param Tenant $tenant The tenant
     * @return bool
     */
    public function isGitHubReady(Tenant $tenant): bool
    {
        return $this->githubIntegration->isReady($tenant);
    }

    // ==================== Browser Integration ====================

    /**
     * Get the browser integration service.
     * 
     * @return BrowserIntegrationService
     */
    public function browser(): BrowserIntegrationService
    {
        return $this->browserIntegration;
    }

    /**
     * Check if browser integration is ready for a tenant.
     * 
     * @param Tenant $tenant The tenant
     * @return bool
     */
    public function isBrowserReady(Tenant $tenant): bool
    {
        return $this->browserIntegration->isReady($tenant);
    }

    // ==================== MCP Registry ====================

    /**
     * Get the MCP registry.
     * 
     * @return McpRegistry
     */
    public function mcp(): McpRegistry
    {
        return $this->microsoftIntegration->getMcpRegistry();
    }

    // ==================== Status Checks ====================

    /**
     * Get the integration status for a tenant.
     * 
     * @param Tenant $tenant The tenant
     * @return array Integration status
     */
    public function getStatus(Tenant $tenant): array
    {
        $stats = $this->getStatistics($tenant);
        
        return [
            'total' => $stats['total'] ?? 0,
            'enabled' => $stats['enabled'] ?? 0,
            'configured' => $stats['configured'] ?? 0,
            'connected' => $stats['connected'] ?? 0,
            'ready' => $stats['ready'] ?? 0,
            'microsoft' => [
                'ready' => $this->isMicrosoftReady($tenant),
                'capabilities' => $this->microsoft()->getCapabilities(),
            ],
            'google' => [
                'ready' => $this->isGoogleReady($tenant),
                'capabilities' => $this->google()->getCapabilities(),
            ],
            'github' => [
                'ready' => $this->isGitHubReady($tenant),
                'capabilities' => $this->github()->getCapabilities(),
            ],
            'browser' => [
                'ready' => $this->isBrowserReady($tenant),
                'capabilities' => $this->browser()->getCapabilities(),
            ],
        ];
    }

    /**
     * Get integrations that provide a specific capability.
     * 
     * @param Tenant $tenant The tenant
     * @param string $capability The capability
     * @return Integration[]
     */
    public function findByCapability(Tenant $tenant, string $capability): array
    {
        return $this->integrationService->findByCapability($tenant, $capability);
    }

    /**
     * Execute an action using the appropriate integration.
     * 
     * @param Tenant $tenant The tenant
     * @param User $user The user
     * @param string $action The action to execute
     * @param array $parameters Action parameters
     * @return array Execution result
     */
    public function executeAction(
        Tenant $tenant,
        User $user,
        string $action,
        array $parameters = []
    ): array {
        // Determine which integration to use based on the action
        $result = [
            'action' => $action,
            'success' => false,
            'message' => 'Action not supported',
            'data' => null,
        ];

        try {
            // Route to the appropriate integration service
            switch ($action) {
                // Microsoft actions
                case 'email:send':
                case 'email:read':
                case 'email:delete':
                case 'calendar:read':
                case 'calendar:write':
                case 'contacts:read':
                case 'contacts:write':
                case 'files:read':
                case 'files:write':
                    if ($this->isMicrosoftReady($tenant)) {
                        $result['data'] = $this->routeMicrosoftAction($action, $tenant, $parameters);
                        $result['success'] = true;
                        $result['message'] = 'Action executed successfully';
                    } else {
                        $result['message'] = 'Microsoft integration not ready';
                    }
                    break;

                // Google actions
                case 'gmail:send':
                case 'gmail:read':
                case 'gmail:delete':
                case 'drive:read':
                case 'drive:write':
                case 'drive:delete':
                    if ($this->isGoogleReady($tenant)) {
                        $result['data'] = $this->routeGoogleAction($action, $tenant, $parameters);
                        $result['success'] = true;
                        $result['message'] = 'Action executed successfully';
                    } else {
                        $result['message'] = 'Google integration not ready';
                    }
                    break;

                // GitHub actions
                case 'github:read':
                case 'github:write':
                case 'github:repositories':
                case 'github:issues':
                case 'github:pull_requests':
                    if ($this->isGitHubReady($tenant)) {
                        $result['data'] = $this->routeGitHubAction($action, $tenant, $parameters);
                        $result['success'] = true;
                        $result['message'] = 'Action executed successfully';
                    } else {
                        $result['message'] = 'GitHub integration not ready';
                    }
                    break;

                // Browser actions
                case 'web:browse':
                case 'web:scrape':
                case 'web:automate':
                case 'web:search':
                case 'web:navigate':
                case 'web:click':
                case 'web:fill':
                case 'web:extract':
                case 'web:screenshot':
                    if ($this->isBrowserReady($tenant)) {
                        $result['data'] = $this->routeBrowserAction($action, $tenant, $parameters);
                        $result['success'] = true;
                        $result['message'] = 'Action executed successfully';
                    } else {
                        $result['message'] = 'Browser integration not ready';
                    }
                    break;

                default:
                    $result['message'] = sprintf('Unknown action: %s', $action);
                    break;
            }

        } catch (\Exception $e) {
            $result['message'] = $e->getMessage();
            $this->logger->error('Integration action failed', [
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }

        return $result;
    }

    /**
     * Route an action to the Microsoft integration service.
     * 
     * @param string $action The action
     * @param Tenant $tenant The tenant
     * @param array $parameters Action parameters
     * @return mixed The action result
     */
    private function routeMicrosoftAction(string $action, Tenant $tenant, array $parameters)
    {
        switch ($action) {
            case 'email:send':
                return $this->microsoft()->sendEmail(
                    $tenant,
                    $parameters['to'] ?? '',
                    $parameters['subject'] ?? '',
                    $parameters['body'] ?? '',
                    $parameters['isHtml'] ?? true,
                    $parameters['cc'] ?? [],
                    $parameters['bcc'] ?? [],
                    $parameters['attachments'] ?? []
                );

            case 'email:read':
                return $this->microsoft()->listEmails($tenant, $parameters);

            case 'email:delete':
                return $this->microsoft()->deleteEmail(
                    $tenant,
                    $parameters['messageId'] ?? ''
                );

            case 'calendar:read':
                return $this->microsoft()->listEvents($tenant, $parameters);

            case 'calendar:write':
                return $this->microsoft()->createEvent(
                    $tenant,
                    $parameters['subject'] ?? '',
                    $parameters['startDateTime'] ?? '',
                    $parameters['endDateTime'] ?? '',
                    $parameters['attendees'] ?? [],
                    $parameters['description'] ?? '',
                    $parameters['location'] ?? ''
                );

            case 'contacts:read':
                return $this->microsoft()->listContacts($tenant, $parameters);

            case 'contacts:write':
                return $this->microsoft()->createContact(
                    $tenant,
                    $parameters['givenName'] ?? '',
                    $parameters['surname'] ?? '',
                    $parameters['email'] ?? '',
                    $parameters['phone'] ?? '',
                    $parameters['additionalData'] ?? []
                );

            case 'files:read':
                return $this->microsoft()->listFiles(
                    $tenant,
                    $parameters['path'] ?? 'root'
                );

            case 'files:write':
                return $this->microsoft()->uploadFile(
                    $tenant,
                    $parameters['parentId'] ?? 'root',
                    $parameters['fileName'] ?? '',
                    $parameters['content'] ?? ''
                );

            default:
                throw new \InvalidArgumentException("Unknown Microsoft action: {$action}");
        }
    }

    /**
     * Route an action to the Google integration service.
     * 
     * @param string $action The action
     * @param Tenant $tenant The tenant
     * @param array $parameters Action parameters
     * @return mixed The action result
     */
    private function routeGoogleAction(string $action, Tenant $tenant, array $parameters)
    {
        switch ($action) {
            case 'gmail:send':
                return $this->google()->sendEmail(
                    $tenant,
                    $parameters['to'] ?? '',
                    $parameters['subject'] ?? '',
                    $parameters['body'] ?? '',
                    $parameters['isHtml'] ?? true,
                    $parameters['cc'] ?? [],
                    $parameters['bcc'] ?? [],
                    $parameters['attachments'] ?? []
                );

            case 'gmail:read':
                return $this->google()->listEmails($tenant, $parameters);

            case 'gmail:delete':
                return $this->google()->deleteEmail(
                    $tenant,
                    $parameters['messageId'] ?? ''
                );

            case 'drive:read':
                return $this->google()->listFiles(
                    $tenant,
                    $parameters['folderId'] ?? 'root',
                    $parameters
                );

            case 'drive:write':
                return $this->google()->uploadFile(
                    $tenant,
                    $parameters['parentId'] ?? 'root',
                    $parameters['fileName'] ?? '',
                    $parameters['content'] ?? '',
                    $parameters['mimeType'] ?? 'text/plain'
                );

            case 'drive:delete':
                return $this->google()->deleteFile(
                    $tenant,
                    $parameters['fileId'] ?? ''
                );

            default:
                throw new \InvalidArgumentException("Unknown Google action: {$action}");
        }
    }

    /**
     * Route an action to the GitHub integration service.
     * 
     * @param string $action The action
     * @param Tenant $tenant The tenant
     * @param array $parameters Action parameters
     * @return mixed The action result
     */
    private function routeGitHubAction(string $action, Tenant $tenant, array $parameters)
    {
        switch ($action) {
            case 'github:read':
                return $this->github()->listRepositories($tenant, $parameters['type'] ?? 'all');

            case 'github:write':
                return $this->github()->createRepository(
                    $tenant,
                    $parameters['name'] ?? '',
                    $parameters['description'] ?? '',
                    $parameters['isPrivate'] ?? false,
                    $parameters['initializeWithReadme'] ?? true,
                    $parameters['additionalOptions'] ?? []
                );

            case 'github:repositories':
                return $this->github()->getRepository(
                    $tenant,
                    $parameters['owner'] ?? '',
                    $parameters['repo'] ?? ''
                );

            case 'github:issues':
                return $this->github()->listIssues(
                    $tenant,
                    $parameters['owner'] ?? '',
                    $parameters['repo'] ?? '',
                    $parameters
                );

            case 'github:pull_requests':
                return $this->github()->listPullRequests(
                    $tenant,
                    $parameters['owner'] ?? '',
                    $parameters['repo'] ?? '',
                    $parameters
                );

            default:
                throw new \InvalidArgumentException("Unknown GitHub action: {$action}");
        }
    }

    /**
     * Route an action to the browser integration service.
     * 
     * @param string $action The action
     * @param Tenant $tenant The tenant
     * @param array $parameters Action parameters
     * @return mixed The action result
     */
    private function routeBrowserAction(string $action, Tenant $tenant, array $parameters)
    {
        switch ($action) {
            case 'web:browse':
                return $this->browser()->browseAndExtract(
                    $tenant,
                    $parameters['url'] ?? '',
                    $parameters['instructions'] ?? []
                );

            case 'web:scrape':
                return $this->browser()->browseAndExtract(
                    $tenant,
                    $parameters['url'] ?? '',
                    $parameters['instructions'] ?? []
                );

            case 'web:automate':
                return $this->browser()->executeWithAgent(
                    $tenant,
                    $parameters['action'] ?? '',
                    $parameters
                );

            case 'web:search':
                return $this->browser()->search(
                    $tenant,
                    $parameters['query'] ?? '',
                    $parameters['options'] ?? []
                );

            case 'web:navigate':
                return $this->browser()->navigate(
                    $tenant,
                    $parameters['url'] ?? '',
                    $parameters['options'] ?? []
                );

            case 'web:click':
                return $this->browser()->click(
                    $tenant,
                    $parameters['selector'] ?? '',
                    $parameters['options'] ?? []
                );

            case 'web:fill':
                return $this->browser()->fill(
                    $tenant,
                    $parameters['selector'] ?? '',
                    $parameters['value'] ?? ''
                );

            case 'web:extract':
                return $this->browser()->extractText(
                    $tenant,
                    $parameters['selector'] ?? null
                );

            case 'web:screenshot':
                return $this->browser()->takeScreenshot(
                    $tenant,
                    $parameters['path'] ?? null,
                    $parameters['options'] ?? []
                );

            default:
                throw new \InvalidArgumentException("Unknown browser action: {$action}");
        }
    }
}
