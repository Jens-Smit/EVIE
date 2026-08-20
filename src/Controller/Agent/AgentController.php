<?php

namespace App\Controller\Agent;

use App\Entity\AI\AgentExecution;
use App\Entity\AI\Capability;
use App\Entity\AI\Conversation;
use App\Entity\AI\LLMConfiguration;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\User;
use App\Message\ExecuteAgentMessage;
use App\Repository\AI\AgentExecutionRepository;
use App\Repository\AI\CapabilityRepository;
use App\Repository\AI\ConversationRepository;
use App\Repository\AI\LLMConfigurationRepository;
use App\Service\AI\CapabilityRegistry;
use App\Service\AI\CapabilityResolver;
use App\Service\AI\ConversationService;
use App\Service\Security\SecurityGuard;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * AgentController provides agent configuration and execution interfaces.
 * 
 * Features:
 * - Agent configuration management
 * - Agent execution
 * - Agent capability management
 * - Conversation-based agent interaction
 * - Real-time agent execution monitoring
 */
class AgentController extends AbstractController
{
    public function __construct(
        private LLMConfigurationRepository $llmConfigurationRepository,
        private AgentExecutionRepository $executionRepository,
        private ConversationRepository $conversationRepository,
        private CapabilityRepository $capabilityRepository,
        private ConversationService $conversationService,
        private CapabilityRegistry $capabilityRegistry,
        private CapabilityResolver $capabilityResolver,
        private SecurityGuard $securityGuard,
        private MessageBusInterface $messageBus
    ) {
    }

    /**
     * Agent Dashboard - Overview of available agents and capabilities
     */
    #[Route('/agents', name: 'agent_dashboard')]
    #[IsGranted('ROLE_USER')]
    public function dashboard(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $tenant = $user->getTenant();

        // Get LLM configurations
        $llmConfigs = $this->llmConfigurationRepository->findByUser($user->getId());
        $defaultLlmConfig = $this->llmConfigurationRepository->findDefaultByUser($user->getId());

        // Get capabilities
        $capabilities = $this->capabilityRepository->findEnabledByTenant($tenant->getId());
        $readyCapabilities = $this->capabilityRepository->findReadyByTenant($tenant->getId());

        // Get recent conversations
        $conversations = $this->conversationRepository->findByUser($user->getId(), 10);

        // Get recent executions
        $executions = $this->executionRepository->findByUser($user->getId(), 10);

        // Get capability statistics
        $capabilityStats = $this->capabilityRegistry->getStatistics($tenant);

        return $this->render('agent/dashboard.html.twig', [
            'user' => $user,
            'tenant' => $tenant,
            'llmConfigs' => $llmConfigs,
            'defaultLlmConfig' => $defaultLlmConfig,
            'capabilities' => $capabilities,
            'readyCapabilities' => $readyCapabilities,
            'conversations' => $conversations,
            'executions' => $executions,
            'capabilityStats' => $capabilityStats,
            'currentRoute' => 'agent_dashboard',
        ]);
    }

    /**
     * LLM Configuration Management
     */
    #[Route('/agents/llm', name: 'agent_llm_configurations')]
    #[IsGranted('ROLE_USER')]
    public function llmConfigurations(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $tenant = $user->getTenant();
        $llmConfigs = $this->llmConfigurationRepository->findByUser($user->getId());

        return $this->render('agent/llm_configurations.html.twig', [
            'user' => $user,
            'tenant' => $tenant,
            'llmConfigs' => $llmConfigs,
            'providers' => $this->getLlmProviders(),
            'currentRoute' => 'agent_llm_configurations',
        ]);
    }

    /**
     * Create or update LLM Configuration
     */
    #[Route('/agents/llm/save', name: 'agent_llm_save', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function saveLlmConfiguration(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $tenant = $user->getTenant();

        $configId = $request->request->get('config_id');
        $provider = $request->request->get('provider');
        $model = $request->request->get('model');
        $isDefault = $request->request->getBoolean('is_default');
        $configuration = $request->request->all('configuration');

        if ($configId) {
            $llmConfig = $this->llmConfigurationRepository->find($configId);
        } else {
            $llmConfig = new LLMConfiguration();
            $llmConfig->setUser($user);
            $llmConfig->setTenant($tenant);
        }

        $llmConfig->setProvider($provider);
        $llmConfig->setModel($model);
        $llmConfig->setConfiguration($configuration);
        $llmConfig->setIsDefault($isDefault);
        $llmConfig->setIsConfigured(true);

        // If this is the default, unset other defaults
        if ($isDefault) {
            $existingDefaults = $this->llmConfigurationRepository->findBy([
                'user' => $user,
                'isDefault' => true,
            ]);
            
            foreach ($existingDefaults as $existing) {
                if ($existing->getId() !== $llmConfig->getId()) {
                    $existing->setIsDefault(false);
                }
            }
        }

        $entityManager = $this->getDoctrine()->getManager();
        $entityManager->persist($llmConfig);
        $entityManager->flush();

        $this->addFlash('success', 'LLM configuration saved successfully!');

        return $this->redirectToRoute('agent_llm_configurations');
    }

    /**
     * Delete LLM Configuration
     */
    #[Route('/agents/llm/{id}/delete', name: 'agent_llm_delete', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function deleteLlmConfiguration(LLMConfiguration $llmConfig): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        // Check if user owns this configuration
        if ($llmConfig->getUserId() !== $user->getId()) {
            throw $this->createAccessDeniedException('You can only delete your own LLM configurations');
        }

        $entityManager = $this->getDoctrine()->getManager();
        $entityManager->remove($llmConfig);
        $entityManager->flush();

        $this->addFlash('success', 'LLM configuration deleted successfully!');

        return $this->redirectToRoute('agent_llm_configurations');
    }

    /**
     * Set Default LLM Configuration
     */
    #[Route('/agents/llm/{id}/set-default', name: 'agent_llm_set_default', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function setDefaultLlmConfiguration(LLMConfiguration $llmConfig): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        // Check if user owns this configuration
        if ($llmConfig->getUserId() !== $user->getId()) {
            throw $this->createAccessDeniedException('You can only set your own LLM configurations as default');
        }

        // Unset other defaults
        $existingDefaults = $this->llmConfigurationRepository->findBy([
            'user' => $user,
            'isDefault' => true,
        ]);
        
        $entityManager = $this->getDoctrine()->getManager();
        
        foreach ($existingDefaults as $existing) {
            $existing->setIsDefault(false);
            $entityManager->persist($existing);
        }

        $llmConfig->setIsDefault(true);
        $entityManager->persist($llmConfig);
        $entityManager->flush();

        $this->addFlash('success', 'Default LLM configuration updated!');

        return $this->redirectToRoute('agent_llm_configurations');
    }

    /**
     * Capability Management
     */
    #[Route('/agents/capabilities', name: 'agent_capabilities')]
    #[IsGranted('ROLE_USER')]
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

        return $this->render('agent/capabilities.html.twig', [
            'user' => $user,
            'tenant' => $tenant,
            'capabilities' => $capabilities,
            'categories' => $categories,
            'stats' => $stats,
            'categoryFilter' => $category,
            'statusFilter' => $status,
            'currentRoute' => 'agent_capabilities',
        ]);
    }

    /**
     * Install a capability
     */
    #[Route('/agents/capabilities/{identifier}/install', name: 'agent_capability_install', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function installCapability(string $identifier): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $tenant = $user->getTenant();

        try {
            $capability = $this->capabilityRegistry->installCapability($tenant, $identifier);
            $this->addFlash('success', sprintf('Capability "%s" installed successfully!', $capability->getName()));
        } catch (\Exception $e) {
            $this->addFlash('error', sprintf('Failed to install capability: %s', $e->getMessage()));
        }

        return $this->redirectToRoute('agent_capabilities');
    }

    /**
     * Uninstall a capability
     */
    #[Route('/agents/capabilities/{id}/uninstall', name: 'agent_capability_uninstall', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function uninstallCapability(Capability $capability): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $tenant = $user->getTenant();
        
        // Check if capability belongs to the same tenant
        if ($capability->getTenantId() !== $tenant->getId()) {
            throw $this->createAccessDeniedException('You can only uninstall capabilities from your tenant');
        }

        try {
            $this->capabilityRegistry->uninstallCapability($tenant, $capability->getIdentifier());
            $this->addFlash('success', sprintf('Capability "%s" uninstalled successfully!', $capability->getName()));
        } catch (\Exception $e) {
            $this->addFlash('error', sprintf('Failed to uninstall capability: %s', $e->getMessage()));
        }

        return $this->redirectToRoute('agent_capabilities');
    }

    /**
     * Enable a capability
     */
    #[Route('/agents/capabilities/{id}/enable', name: 'agent_capability_enable', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function enableCapability(Capability $capability): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $tenant = $user->getTenant();
        
        // Check if capability belongs to the same tenant
        if ($capability->getTenantId() !== $tenant->getId()) {
            throw $this->createAccessDeniedException('You can only enable capabilities from your tenant');
        }

        try {
            $this->capabilityRegistry->enableCapability($capability);
            $this->addFlash('success', sprintf('Capability "%s" enabled successfully!', $capability->getName()));
        } catch (\Exception $e) {
            $this->addFlash('error', sprintf('Failed to enable capability: %s', $e->getMessage()));
        }

        return $this->redirectToRoute('agent_capabilities');
    }

    /**
     * Disable a capability
     */
    #[Route('/agents/capabilities/{id}/disable', name: 'agent_capability_disable', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function disableCapability(Capability $capability): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $tenant = $user->getTenant();
        
        // Check if capability belongs to the same tenant
        if ($capability->getTenantId() !== $tenant->getId()) {
            throw $this->createAccessDeniedException('You can only disable capabilities from your tenant');
        }

        try {
            $this->capabilityRegistry->disableCapability($capability);
            $this->addFlash('success', sprintf('Capability "%s" disabled successfully!', $capability->getName()));
        } catch (\Exception $e) {
            $this->addFlash('error', sprintf('Failed to disable capability: %s', $e->getMessage()));
        }

        return $this->redirectToRoute('agent_capabilities');
    }

    /**
     * Execute an agent
     */
    #[Route('/agents/execute', name: 'agent_execute', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function executeAgent(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $tenant = $user->getTenant();

        $agentName = $request->request->get('agent_name');
        $parameters = $request->request->all('parameters');
        $conversationId = $request->request->get('conversation_id');

        if (empty($agentName)) {
            return new JsonResponse(['error' => 'Agent name is required'], 400);
        }

        // Check if agent execution is allowed
        $effect = $this->securityGuard->evaluate($tenant, 'agent:execute', null, [
            'agentName' => $agentName,
        ]);

        if ($effect === 'deny') {
            return new JsonResponse(['error' => 'Agent execution is denied by security policy'], 403);
        }

        if ($effect === 'ask') {
            // Request approval
            $approvalId = $this->securityGuard->requestApproval(
                $tenant,
                $user,
                'agent:execute',
                null,
                ['agentName' => $agentName]
            );
            
            return new JsonResponse([
                'status' => 'approval_required',
                'approvalId' => $approvalId,
                'message' => 'Approval required for agent execution',
            ]);
        }

        // Create execution ID
        $executionId = Ulid::generate();

        // Create message
        $message = new ExecuteAgentMessage(
            executionId: $executionId,
            userId: $user->getId(),
            tenantId: $tenant->getId(),
            agentName: $agentName,
            conversationId: $conversationId,
            parameters: $parameters,
            metadata: [
                'source' => 'web_ui',
                'userAgent' => $request->headers->get('User-Agent'),
            ]
        );

        // Dispatch message
        $this->messageBus->dispatch($message);

        return new JsonResponse([
            'status' => 'queued',
            'executionId' => $executionId,
            'message' => 'Agent execution queued successfully',
        ]);
    }

    /**
     * Execute an agent and wait for result (synchronous)
     */
    #[Route('/agents/execute-sync', name: 'agent_execute_sync', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function executeAgentSync(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $tenant = $user->getTenant();

        $agentName = $request->request->get('agent_name');
        $parameters = $request->request->all('parameters');
        $timeout = $request->request->getInt('timeout', 30); // seconds

        if (empty($agentName)) {
            return new JsonResponse(['error' => 'Agent name is required'], 400);
        }

        // For synchronous execution, we would typically:
        // 1. Dispatch the message
        // 2. Wait for the result (with timeout)
        // 3. Return the result
        
        // For now, we'll just queue it and return the execution ID
        // In a real implementation, you would use a synchronous transport
        // or implement a polling mechanism

        $executionId = Ulid::generate();

        $message = new ExecuteAgentMessage(
            executionId: $executionId,
            userId: $user->getId(),
            tenantId: $tenant->getId(),
            agentName: $agentName,
            parameters: $parameters,
            metadata: [
                'source' => 'web_ui_sync',
            ]
        );

        $this->messageBus->dispatch($message);

        return new JsonResponse([
            'status' => 'queued',
            'executionId' => $executionId,
            'message' => 'Agent execution queued (synchronous execution not yet implemented)',
        ]);
    }

    /**
     * Get agent execution status
     */
    #[Route('/agents/executions/{id}/status', name: 'agent_execution_status')]
    #[IsGranted('ROLE_USER')]
    public function executionStatus(AgentExecution $execution): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $tenant = $user->getTenant();
        
        // Check if execution belongs to the same tenant
        if ($execution->getTenantId() !== $tenant->getId()) {
            return new JsonResponse(['error' => 'Access denied'], 403);
        }

        return new JsonResponse([
            'id' => $execution->getId(),
            'status' => $execution->getStatus(),
            'agent' => $execution->getAgent(),
            'createdAt' => $execution->getCreatedAt()->format('c'),
            'startedAt' => $execution->getStartedAt()?->format('c'),
            'completedAt' => $execution->getCompletedAt()?->format('c'),
            'duration' => $execution->getDuration(),
            'error' => $execution->getError(),
            'results' => $execution->getResults(),
            'metadata' => $execution->getMetadata(),
        ]);
    }

    /**
     * List agent executions
     */
    #[Route('/agents/executions', name: 'agent_executions')]
    #[IsGranted('ROLE_USER')]
    public function executions(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $tenant = $user->getTenant();

        $status = $request->query->get('status');
        $limit = $request->query->getInt('limit', 20);

        $query = ['tenant' => $tenant];
        
        if ($status) {
            $query['status'] = $status;
        }

        $executions = $this->executionRepository->findBy(
            $query,
            ['createdAt' => 'DESC'],
            $limit
        );

        return $this->render('agent/executions.html.twig', [
            'user' => $user,
            'tenant' => $tenant,
            'executions' => $executions,
            'statusFilter' => $status,
            'currentRoute' => 'agent_executions',
        ]);
    }

    /**
     * Create a new conversation
     */
    #[Route('/agents/conversations/new', name: 'agent_conversation_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function newConversation(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        if ($request->isMethod('POST')) {
            $title = $request->request->get('title');
            
            $conversation = $this->conversationService->createConversation(
                $user,
                $title ?: null
            );

            $this->addFlash('success', 'New conversation created!');
            
            return $this->redirectToRoute('agent_conversation_view', [
                'id' => $conversation->getId(),
            ]);
        }

        return $this->render('agent/new_conversation.html.twig', [
            'user' => $user,
            'currentRoute' => 'agent_conversations',
        ]);
    }

    /**
     * View a conversation
     */
    #[Route('/agents/conversations/{id}', name: 'agent_conversation_view')]
    #[IsGranted('ROLE_USER')]
    public function viewConversation(Conversation $conversation): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $tenant = $user->getTenant();
        
        // Check if conversation belongs to the same user
        if ($conversation->getUserId() !== $user->getId()) {
            throw $this->createAccessDeniedException('You can only view your own conversations');
        }

        $messages = $this->conversationService->getRecentMessages($conversation, 50);
        $context = $this->conversationService->getContext($conversation);
        $summary = $this->conversationService->getSummary($conversation);
        $tags = $this->conversationService->retrieveTags($conversation);

        // Get available agents/capabilities
        $capabilities = $this->capabilityRepository->findReadyByTenant($tenant->getId());

        return $this->render('agent/conversation_view.html.twig', [
            'user' => $user,
            'tenant' => $tenant,
            'conversation' => $conversation,
            'messages' => $messages,
            'context' => $context,
            'summary' => $summary,
            'tags' => $tags,
            'capabilities' => $capabilities,
            'currentRoute' => 'agent_conversations',
        ]);
    }

    /**
     * Send a message in a conversation
     */
    #[Route('/agents/conversations/{id}/send', name: 'agent_conversation_send', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function sendMessage(Conversation $conversation, Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        // Check if conversation belongs to the same user
        if ($conversation->getUserId() !== $user->getId()) {
            return new JsonResponse(['error' => 'Access denied'], 403);
        }

        $content = $request->request->get('content');
        $agentName = $request->request->get('agent_name');

        if (empty($content)) {
            return new JsonResponse(['error' => 'Message content is required'], 400);
        }

        // Add user message
        $message = $this->conversationService->addUserMessage($conversation, $content);

        // If an agent is specified, execute it
        if (!empty($agentName)) {
            // Check if agent execution is allowed
            $effect = $this->securityGuard->evaluate(
                $conversation->getTenant(),
                'agent:execute',
                null,
                ['agentName' => $agentName]
            );

            if ($effect === 'deny') {
                // Add error message
                $this->conversationService->addAssistantMessage(
                    $conversation,
                    'Sorry, I cannot execute that agent due to security policies.'
                );
            } elseif ($effect === 'ask') {
                // Request approval
                $approvalId = $this->securityGuard->requestApproval(
                    $conversation->getTenant(),
                    $user,
                    'agent:execute',
                    null,
                    ['agentName' => $agentName, 'conversationId' => $conversation->getId()]
                );
                
                // Add pending message
                $this->conversationService->addAssistantMessage(
                    $conversation,
                    sprintf('Approval required for agent "%s". Approval ID: %s', $agentName, substr($approvalId, 0, 8))
                );
            } else {
                // Execute agent
                $executionId = Ulid::generate();
                
                $agentMessage = new ExecuteAgentMessage(
                    executionId: $executionId,
                    userId: $user->getId(),
                    tenantId: $conversation->getTenantId(),
                    agentName: $agentName,
                    conversationId: $conversation->getId(),
                    parameters: [],
                    metadata: [
                        'source' => 'conversation',
                        'messageId' => $message->getId(),
                    ]
                );

                $this->messageBus->dispatch($agentMessage);

                // Add assistant message with execution ID
                $this->conversationService->addAssistantMessage(
                    $conversation,
                    sprintf('Agent "%s" execution queued (ID: %s). Processing...', $agentName, substr($executionId, 0, 8))
                );
            }
        }

        // Auto-title the conversation if it's new
        if ($conversation->getMessageCount() === 1) {
            $this->conversationService->autoTitle($conversation);
        }

        return new JsonResponse([
            'status' => 'success',
            'messageId' => $message->getId(),
            'executionId' => $agentName ? Ulid::generate() : null,
        ]);
    }

    /**
     * List conversations
     */
    #[Route('/agents/conversations', name: 'agent_conversations')]
    #[IsGranted('ROLE_USER')]
    public function conversations(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $tenant = $user->getTenant();

        $status = $request->query->get('status');
        $tag = $request->query->get('tag');
        $limit = $request->query->getInt('limit', 20);

        $conversations = $this->conversationRepository->findByUser($user->getId());
        
        // Filter by status
        if ($status) {
            $conversations = array_filter($conversations, function($c) use ($status) {
                return $c->getStatus() === $status;
            });
        }

        // Filter by tag
        if ($tag) {
            $conversations = array_filter($conversations, function($c) use ($tag) {
                return $this->conversationService->hasTag($c, $tag);
            });
        }

        // Sort by updatedAt (newest first)
        usort($conversations, function($a, $b) {
            return $b->getUpdatedAt() <=> $a->getUpdatedAt();
        });

        $conversations = array_slice($conversations, 0, $limit);

        // Get all tags
        $allTags = [];
        foreach ($conversations as $conversation) {
            $tags = $this->conversationService->retrieveTags($conversation);
            foreach ($tags as $t) {
                if (!in_array($t, $allTags, true)) {
                    $allTags[] = $t;
                }
            }
        }

        return $this->render('agent/conversations.html.twig', [
            'user' => $user,
            'tenant' => $tenant,
            'conversations' => $conversations,
            'allTags' => $allTags,
            'statusFilter' => $status,
            'tagFilter' => $tag,
            'currentRoute' => 'agent_conversations',
        ]);
    }

    /**
     * Delete a conversation
     */
    #[Route('/agents/conversations/{id}/delete', name: 'agent_conversation_delete', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function deleteConversation(Conversation $conversation): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        // Check if conversation belongs to the same user
        if ($conversation->getUserId() !== $user->getId()) {
            throw $this->createAccessDeniedException('You can only delete your own conversations');
        }

        $entityManager = $this->getDoctrine()->getManager();
        $entityManager->remove($conversation);
        $entityManager->flush();

        $this->addFlash('success', 'Conversation deleted successfully!');

        return $this->redirectToRoute('agent_conversations');
    }

    /**
     * Rename a conversation
     */
    #[Route('/agents/conversations/{id}/rename', name: 'agent_conversation_rename', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function renameConversation(Conversation $conversation, Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        // Check if conversation belongs to the same user
        if ($conversation->getUserId() !== $user->getId()) {
            return new JsonResponse(['error' => 'Access denied'], 403);
        }

        $title = $request->request->get('title');
        
        if (empty($title)) {
            return new JsonResponse(['error' => 'Title is required'], 400);
        }

        $this->conversationService->renameConversation($conversation, $title);

        return new JsonResponse([
            'status' => 'success',
            'title' => $title,
        ]);
    }

    /**
     * Add a tag to a conversation
     */
    #[Route('/agents/conversations/{id}/tag', name: 'agent_conversation_tag', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function tagConversation(Conversation $conversation, Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        // Check if conversation belongs to the same user
        if ($conversation->getUserId() !== $user->getId()) {
            return new JsonResponse(['error' => 'Access denied'], 403);
        }

        $tag = $request->request->get('tag');
        
        if (empty($tag)) {
            return new JsonResponse(['error' => 'Tag is required'], 400);
        }

        $this->conversationService->addTag($conversation, $tag);

        return new JsonResponse([
            'status' => 'success',
            'tag' => $tag,
        ]);
    }

    /**
     * Remove a tag from a conversation
     */
    #[Route('/agents/conversations/{id}/untag', name: 'agent_conversation_untag', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function untagConversation(Conversation $conversation, Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        // Check if conversation belongs to the same user
        if ($conversation->getUserId() !== $user->getId()) {
            return new JsonResponse(['error' => 'Access denied'], 403);
        }

        $tag = $request->request->get('tag');
        
        if (empty($tag)) {
            return new JsonResponse(['error' => 'Tag is required'], 400);
        }

        $this->conversationService->removeTag($conversation, $tag);

        return new JsonResponse([
            'status' => 'success',
            'tag' => $tag,
        ]);
    }

    /**
     * Get available LLM providers
     */
    private function getLlmProviders(): array
    {
        return [
            [
                'identifier' => 'mistral',
                'name' => 'Mistral AI',
                'models' => [
                    'mistral-tiny' => 'Mistral Tiny',
                    'mistral-small' => 'Mistral Small',
                    'mistral-medium' => 'Mistral Medium',
                    'mistral-large' => 'Mistral Large',
                ],
            ],
            [
                'identifier' => 'openai',
                'name' => 'OpenAI',
                'models' => [
                    'gpt-4o-mini' => 'GPT-4o Mini',
                    'gpt-4o' => 'GPT-4o',
                    'gpt-4' => 'GPT-4',
                    'gpt-3.5-turbo' => 'GPT-3.5 Turbo',
                ],
            ],
            [
                'identifier' => 'anthropic',
                'name' => 'Anthropic',
                'models' => [
                    'claude-3-haiku' => 'Claude 3 Haiku',
                    'claude-3-sonnet' => 'Claude 3 Sonnet',
                    'claude-3-opus' => 'Claude 3 Opus',
                ],
            ],
            [
                'identifier' => 'google',
                'name' => 'Google',
                'models' => [
                    'gemini-1.5-flash' => 'Gemini 1.5 Flash',
                    'gemini-1.5-pro' => 'Gemini 1.5 Pro',
                ],
            ],
        ];
    }
}
