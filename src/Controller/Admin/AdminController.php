<?php

namespace App\Controller\Admin;

use App\Entity\AI\AgentExecution;
use App\Entity\AI\Capability;
use App\Entity\AI\Conversation;
use App\Entity\AI\LLMConfiguration;
use App\Entity\Integration\Integration;
use App\Entity\Integration\McpServer;
use App\Entity\Security\Policy;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\User;
use App\Repository\AI\AgentExecutionRepository;
use App\Repository\AI\CapabilityRepository;
use App\Repository\AI\ConversationRepository;
use App\Repository\AI\LLMConfigurationRepository;
use App\Repository\Integration\IntegrationRepository;
use App\Repository\Integration\McpServerRepository;
use App\Repository\Security\PolicyRepository;
use App\Repository\Tenant\TenantRepository;
use App\Repository\Tenant\UserRepository;
use App\Service\AI\CapabilityRegistry;
use App\Service\AI\ConversationService;
use App\Service\Automation\SchedulerService;
use App\Service\Integration\IntegrationManager;
use App\Service\Security\SecurityGuard;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * AdminController provides the admin dashboard and management interfaces.
 * 
 * Features:
 * - Dashboard with system overview
 * - Tenant management
 * - User management
 * - Execution monitoring
 * - Integration status
 * - Capability management
 * - Policy management
 */
class AdminController extends AbstractController
{
    public function __construct(
        private TenantRepository $tenantRepository,
        private UserRepository $userRepository,
        private AgentExecutionRepository $executionRepository,
        private ConversationRepository $conversationRepository,
        private LLMConfigurationRepository $llmConfigurationRepository,
        private CapabilityRepository $capabilityRepository,
        private IntegrationRepository $integrationRepository,
        private McpServerRepository $mcpServerRepository,
        private PolicyRepository $policyRepository,
        private ConversationService $conversationService,
        private CapabilityRegistry $capabilityRegistry,
        private SchedulerService $schedulerService,
        private IntegrationManager $integrationManager,
        private SecurityGuard $securityGuard
    ) {
    }

    /**
     * Admin Dashboard - System Overview
     */
    #[Route('/admin', name: 'admin_dashboard')]
    #[IsGranted('ROLE_ADMIN')]
    public function dashboard(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $tenant = $user->getTenant();

        // Get statistics
        $stats = [
            'tenants' => $this->tenantRepository->count([]),
            'users' => $this->userRepository->count([]),
            'executions' => $this->executionRepository->count([]),
            'conversations' => $this->conversationRepository->count([]),
            'llmConfigurations' => $this->llmConfigurationRepository->count([]),
            'capabilities' => $this->capabilityRepository->count([]),
            'integrations' => $this->integrationRepository->count([]),
            'mcpServers' => $this->mcpServerRepository->count([]),
            'policies' => $this->policyRepository->count([]),
        ];

        // Get recent executions
        $recentExecutions = $this->executionRepository->findBy(
            ['tenant' => $tenant],
            ['createdAt' => 'DESC'],
            10
        );

        // Get ready integrations
        $readyIntegrations = $this->integrationManager->getReadyIntegrations($tenant);

        // Get capability statistics
        $capabilityStats = $this->capabilityRegistry->getStatistics($tenant);

        // Get policy statistics
        $policyStats = $this->securityGuard->getStatistics($tenant);

        return $this->render('admin/dashboard.html.twig', [
            'user' => $user,
            'tenant' => $tenant,
            'stats' => $stats,
            'recentExecutions' => $recentExecutions,
            'readyIntegrations' => $readyIntegrations,
            'capabilityStats' => $capabilityStats,
            'policyStats' => $policyStats,
            'currentRoute' => 'admin_dashboard',
        ]);
    }

    /**
     * Tenant Management
     */
    #[Route('/admin/tenants', name: 'admin_tenants')]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function tenants(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $tenants = $this->tenantRepository->findAll();

        return $this->render('admin/tenants.html.twig', [
            'user' => $user,
            'tenants' => $tenants,
            'currentRoute' => 'admin_tenants',
        ]);
    }

    /**
     * Tenant Detail View
     */
    #[Route('/admin/tenants/{id}', name: 'admin_tenant_detail')]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function tenantDetail(Tenant $tenant): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $users = $this->userRepository->findByTenant($tenant->getId());
        $executions = $this->executionRepository->findByTenant($tenant->getId(), 20);
        $conversations = $this->conversationRepository->findByTenant($tenant->getId(), 20);
        $capabilities = $this->capabilityRepository->findByTenant($tenant->getId());
        $integrations = $this->integrationRepository->findByTenant($tenant->getId());
        $policies = $this->policyRepository->findByTenant($tenant->getId());

        return $this->render('admin/tenant_detail.html.twig', [
            'user' => $user,
            'tenant' => $tenant,
            'users' => $users,
            'executions' => $executions,
            'conversations' => $conversations,
            'capabilities' => $capabilities,
            'integrations' => $integrations,
            'policies' => $policies,
            'currentRoute' => 'admin_tenants',
        ]);
    }

    /**
     * User Management
     */
    #[Route('/admin/users', name: 'admin_users')]
    #[IsGranted('ROLE_ADMIN')]
    public function users(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $tenant = $user->getTenant();
        $users = $this->userRepository->findByTenant($tenant->getId());

        return $this->render('admin/users.html.twig', [
            'user' => $user,
            'tenant' => $tenant,
            'users' => $users,
            'currentRoute' => 'admin_users',
        ]);
    }

    /**
     * User Detail View
     */
    #[Route('/admin/users/{id}', name: 'admin_user_detail')]
    #[IsGranted('ROLE_ADMIN')]
    public function userDetail(User $userEntity): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $tenant = $user->getTenant();
        
        // Check if user belongs to the same tenant
        if ($userEntity->getTenantId() !== $tenant->getId()) {
            throw $this->createAccessDeniedException('You can only view users from your tenant');
        }

        $conversations = $this->conversationRepository->findByUser($userEntity->getId(), 20);
        $llmConfigs = $this->llmConfigurationRepository->findByUser($userEntity->getId());
        $executions = $this->executionRepository->findByUser($userEntity->getId(), 20);

        return $this->render('admin/user_detail.html.twig', [
            'currentUser' => $user,
            'user' => $userEntity,
            'tenant' => $tenant,
            'conversations' => $conversations,
            'llmConfigs' => $llmConfigs,
            'executions' => $executions,
            'currentRoute' => 'admin_users',
        ]);
    }

    /**
     * Execution Monitoring
     */
    #[Route('/admin/executions', name: 'admin_executions')]
    #[IsGranted('ROLE_ADMIN')]
    public function executions(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $tenant = $user->getTenant();
        
        $status = $request->query->get('status');
        $limit = $request->query->getInt('limit', 50);

        $query = ['tenant' => $tenant];
        
        if ($status) {
            $query['status'] = $status;
        }

        $executions = $this->executionRepository->findBy(
            $query,
            ['createdAt' => 'DESC'],
            $limit
        );

        return $this->render('admin/executions.html.twig', [
            'user' => $user,
            'tenant' => $tenant,
            'executions' => $executions,
            'statusFilter' => $status,
            'currentRoute' => 'admin_executions',
        ]);
    }

    /**
     * Execution Detail View
     */
    #[Route('/admin/executions/{id}', name: 'admin_execution_detail')]
    #[IsGranted('ROLE_ADMIN')]
    public function executionDetail(AgentExecution $execution): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $tenant = $user->getTenant();
        
        // Check if execution belongs to the same tenant
        if ($execution->getTenantId() !== $tenant->getId()) {
            throw $this->createAccessDeniedException('You can only view executions from your tenant');
        }

        $messages = $execution->getMessages();
        $childExecutions = $execution->getChildExecutions();

        return $this->render('admin/execution_detail.html.twig', [
            'user' => $user,
            'tenant' => $tenant,
            'execution' => $execution,
            'messages' => $messages,
            'childExecutions' => $childExecutions,
            'currentRoute' => 'admin_executions',
        ]);
    }

    /**
     * Conversation Management
     */
    #[Route('/admin/conversations', name: 'admin_conversations')]
    #[IsGranted('ROLE_ADMIN')]
    public function conversations(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $tenant = $user->getTenant();
        
        $status = $request->query->get('status');
        $limit = $request->query->getInt('limit', 50);

        $query = ['tenant' => $tenant];
        
        if ($status) {
            $query['status'] = $status;
        }

        $conversations = $this->conversationRepository->findBy(
            $query,
            ['updatedAt' => 'DESC'],
            $limit
        );

        return $this->render('admin/conversations.html.twig', [
            'user' => $user,
            'tenant' => $tenant,
            'conversations' => $conversations,
            'statusFilter' => $status,
            'currentRoute' => 'admin_conversations',
        ]);
    }

    /**
     * Conversation Detail View
     */
    #[Route('/admin/conversations/{id}', name: 'admin_conversation_detail')]
    #[IsGranted('ROLE_ADMIN')]
    public function conversationDetail(Conversation $conversation): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $tenant = $user->getTenant();
        
        // Check if conversation belongs to the same tenant
        if ($conversation->getTenantId() !== $tenant->getId()) {
            throw $this->createAccessDeniedException('You can only view conversations from your tenant');
        }

        $messages = $this->conversationService->getRecentMessages($conversation, 50);
        $context = $this->conversationService->getContext($conversation);
        $summary = $this->conversationService->getSummary($conversation);
        $tags = $this->conversationService->retrieveTags($conversation);

        return $this->render('admin/conversation_detail.html.twig', [
            'user' => $user,
            'tenant' => $tenant,
            'conversation' => $conversation,
            'messages' => $messages,
            'context' => $context,
            'summary' => $summary,
            'tags' => $tags,
            'currentRoute' => 'admin_conversations',
        ]);
    }

    /**
     * Integration Management
     */
    #[Route('/admin/integrations', name: 'admin_integrations')]
    #[IsGranted('ROLE_ADMIN')]
    public function integrations(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $tenant = $user->getTenant();
        
        $type = $request->query->get('type');
        $status = $request->query->get('status');

        $integrations = $this->integrationRepository->findByTenant($tenant->getId());
        
        // Filter by type
        if ($type) {
            $integrations = array_filter($integrations, function($i) use ($type) {
                return $i->getType() === $type;
            });
        }

        // Filter by status
        if ($status) {
            $integrations = array_filter($integrations, function($i) use ($status) {
                return $i->getConnectionStatus() === $status;
            });
        }

        $integrationTypes = $this->integrationManager->getIntegrationTypes();
        $allCapabilities = $this->integrationManager->getAllCapabilities($tenant);

        return $this->render('admin/integrations.html.twig', [
            'user' => $user,
            'tenant' => $tenant,
            'integrations' => $integrations,
            'integrationTypes' => $integrationTypes,
            'allCapabilities' => $allCapabilities,
            'typeFilter' => $type,
            'statusFilter' => $status,
            'currentRoute' => 'admin_integrations',
        ]);
    }

    /**
     * Integration Detail View
     */
    #[Route('/admin/integrations/{id}', name: 'admin_integration_detail')]
    #[IsGranted('ROLE_ADMIN')]
    public function integrationDetail(Integration $integration): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $tenant = $user->getTenant();
        
        // Check if integration belongs to the same tenant
        if ($integration->getTenantId() !== $tenant->getId()) {
            throw $this->createAccessDeniedException('You can only view integrations from your tenant');
        }

        $capabilities = $integration->getCapabilities() ?? [];
        $scopes = $integration->getScopes() ?? [];

        return $this->render('admin/integration_detail.html.twig', [
            'user' => $user,
            'tenant' => $tenant,
            'integration' => $integration,
            'capabilities' => $capabilities,
            'scopes' => $scopes,
            'currentRoute' => 'admin_integrations',
        ]);
    }

    /**
     * MCP Server Management
     */
    #[Route('/admin/mcp-servers', name: 'admin_mcp_servers')]
    #[IsGranted('ROLE_ADMIN')]
    public function mcpServers(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $tenant = $user->getTenant();
        
        $status = $request->query->get('status');

        $mcpServers = $this->mcpServerRepository->findByTenant($tenant->getId());
        
        // Filter by status
        if ($status) {
            $mcpServers = array_filter($mcpServers, function($s) use ($status) {
                return $s->getConnectionStatus() === $status;
            });
        }

        $allTools = $this->integrationManager->mcp()->getAllTools($tenant);
        $allResources = $this->integrationManager->mcp()->getAllResources($tenant);

        return $this->render('admin/mcp_servers.html.twig', [
            'user' => $user,
            'tenant' => $tenant,
            'mcpServers' => $mcpServers,
            'allTools' => $allTools,
            'allResources' => $allResources,
            'statusFilter' => $status,
            'currentRoute' => 'admin_mcp_servers',
        ]);
    }

    /**
     * Capability Management
     */
    #[Route('/admin/capabilities', name: 'admin_capabilities')]
    #[IsGranted('ROLE_ADMIN')]
    public function capabilities(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $tenant = $user->getTenant();
        
        $category = $request->query->get('category');
        $status = $request->query->get('status');

        $capabilities = $this->capabilityRepository->findByTenant($tenant->getId());
        
        // Filter by category
        if ($category) {
            $capabilities = array_filter($capabilities, function($c) use ($category) {
                return $c->getCategory() === $category;
            });
        }

        // Filter by status
        if ($status) {
            $capabilities = array_filter($capabilities, function($c) use ($status) {
                if ($status === 'ready') {
                    return $c->isReady();
                }
                if ($status === 'installed') {
                    return $c->isInstalled();
                }
                if ($status === 'configured') {
                    return $c->isConfigured();
                }
                if ($status === 'enabled') {
                    return $c->isEnabled();
                }
                return false;
            });
        }

        $categories = $this->capabilityRegistry->getCategories();
        $stats = $this->capabilityRegistry->getStatistics($tenant);

        return $this->render('admin/capabilities.html.twig', [
            'user' => $user,
            'tenant' => $tenant,
            'capabilities' => $capabilities,
            'categories' => $categories,
            'stats' => $stats,
            'categoryFilter' => $category,
            'statusFilter' => $status,
            'currentRoute' => 'admin_capabilities',
        ]);
    }

    /**
     * Policy Management
     */
    #[Route('/admin/policies', name: 'admin_policies')]
    #[IsGranted('ROLE_ADMIN')]
    public function policies(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $tenant = $user->getTenant();
        
        $type = $request->query->get('type');
        $effect = $request->query->get('effect');

        $policies = $this->policyRepository->findByTenant($tenant->getId());
        
        // Filter by type
        if ($type) {
            $policies = array_filter($policies, function($p) use ($type) {
                return $p->getPolicyType() === $type;
            });
        }

        // Filter by effect
        if ($effect) {
            $policies = array_filter($policies, function($p) use ($effect) {
                return $p->getEffect() === $effect;
            });
        }

        $stats = $this->securityGuard->getStatistics($tenant);

        return $this->render('admin/policies.html.twig', [
            'user' => $user,
            'tenant' => $tenant,
            'policies' => $policies,
            'stats' => $stats,
            'typeFilter' => $type,
            'effectFilter' => $effect,
            'currentRoute' => 'admin_policies',
        ]);
    }

    /**
     * Policy Detail View
     */
    #[Route('/admin/policies/{id}', name: 'admin_policy_detail')]
    #[IsGranted('ROLE_ADMIN')]
    public function policyDetail(Policy $policy): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $tenant = $user->getTenant();
        
        // Check if policy belongs to the same tenant
        if ($policy->getTenantId() !== $tenant->getId()) {
            throw $this->createAccessDeniedException('You can only view policies from your tenant');
        }

        return $this->render('admin/policy_detail.html.twig', [
            'user' => $user,
            'tenant' => $tenant,
            'policy' => $policy,
            'currentRoute' => 'admin_policies',
        ]);
    }

    /**
     * Scheduler Management
     */
    #[Route('/admin/scheduler', name: 'admin_scheduler')]
    #[IsGranted('ROLE_ADMIN')]
    public function scheduler(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $tenant = $user->getTenant();

        // Get scheduled tasks (would be implemented with ScheduledTaskRepository)
        // For now, we'll use a placeholder
        $scheduledTasks = [];

        // Get scheduler statistics
        $stats = $this->schedulerService->getStatistics($tenant);

        return $this->render('admin/scheduler.html.twig', [
            'user' => $user,
            'tenant' => $tenant,
            'scheduledTasks' => $scheduledTasks,
            'stats' => $stats,
            'currentRoute' => 'admin_scheduler',
        ]);
    }

    /**
     * System Health Check
     */
    #[Route('/admin/health', name: 'admin_health')]
    #[IsGranted('ROLE_ADMIN')]
    public function health(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $tenant = $user->getTenant();

        // Check database connection
        $dbHealth = true;
        try {
            $this->tenantRepository->count([]);
        } catch (\Exception $e) {
            $dbHealth = false;
        }

        // Check Messenger
        $messengerHealth = true; // Would check messenger queue

        // Check integrations
        $integrationHealth = [];
        $integrations = $this->integrationManager->getEnabledIntegrations($tenant);
        foreach ($integrations as $integration) {
            $integrationHealth[$integration->getIdentifier()] = [
                'name' => $integration->getName(),
                'type' => $integration->getType(),
                'connected' => $integration->isConnected(),
                'configured' => $integration->isConfigured(),
            ];
        }

        // Check MCP servers
        $mcpHealth = [];
        $mcpServers = $this->integrationManager->mcp()->getMcpServersByTenant($tenant);
        foreach ($mcpServers as $server) {
            $mcpHealth[$server->getIdentifier()] = [
                'name' => $server->getName(),
                'connected' => $server->isConnected(),
                'enabled' => $server->isEnabled(),
            ];
        }

        return $this->render('admin/health.html.twig', [
            'user' => $user,
            'tenant' => $tenant,
            'dbHealth' => $dbHealth,
            'messengerHealth' => $messengerHealth,
            'integrationHealth' => $integrationHealth,
            'mcpHealth' => $mcpHealth,
            'currentRoute' => 'admin_health',
        ]);
    }
}
