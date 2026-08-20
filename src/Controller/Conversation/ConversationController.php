<?php

namespace App\Controller\Conversation;

use App\Entity\AI\Conversation;
use App\Entity\AI\Message;
use App\Entity\Tenant\User;
use App\Message\ExecuteAgentMessage;
use App\Repository\AI\ConversationRepository;
use App\Repository\AI\MessageRepository;
use App\Service\AI\ConversationService;
use App\Service\Security\SecurityGuard;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * ConversationController provides the chat interface for conversations.
 * 
 * Features:
 * - Conversation list and management
 * - Chat interface with message history
 * - Agent execution from chat
 * - Real-time updates (via polling or WebSocket)
 * - Context management
 * - Token usage tracking
 */
class ConversationController extends AbstractController
{
    public function __construct(
        private ConversationRepository $conversationRepository,
        private MessageRepository $messageRepository,
        private ConversationService $conversationService,
        private SecurityGuard $securityGuard,
        private MessageBusInterface $messageBus
    ) {
    }

    /**
     * List all conversations for the current user
     */
    #[Route('/chat', name: 'chat_index')]
    #[IsGranted('ROLE_USER')]
    public function index(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $status = $request->query->get('status');
        $tag = $request->query->get('tag');
        $search = $request->query->get('search');

        $conversations = $this->conversationRepository->findByUser($user->getId());

        // Filter conversations
        if ($status) {
            $conversations = array_filter($conversations, function($c) use ($status) {
                return $c->getStatus() === $status;
            });
        }

        if ($tag) {
            $conversations = array_filter($conversations, function($c) use ($tag) {
                return $this->conversationService->hasTag($c, $tag);
            });
        }

        if ($search) {
            $conversations = array_filter($conversations, function($c) use ($search) {
                return stripos($c->getTitle(), $search) !== false ||
                       stripos($c->getId(), $search) !== false;
            });
        }

        // Sort by updatedAt (newest first)
        usort($conversations, function($a, $b) {
            return $b->getUpdatedAt() <=> $a->getUpdatedAt();
        });

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

        return $this->render('conversation/index.html.twig', [
            'user' => $user,
            'conversations' => $conversations,
            'allTags' => $allTags,
            'statusFilter' => $status,
            'tagFilter' => $tag,
            'searchFilter' => $search,
            'currentRoute' => 'chat',
        ]);
    }

    /**
     * Create a new conversation
     */
    #[Route('/chat/new', name: 'chat_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function newConversation(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        if ($request->isMethod('POST')) {
            $title = $request->request->get('title');
            $firstMessage = $request->request->get('first_message');
            
            $conversation = $this->conversationService->createConversation(
                $user,
                $title ?: null
            );

            // Add first message if provided
            if (!empty($firstMessage)) {
                $this->conversationService->addUserMessage($conversation, $firstMessage);
                $this->conversationService->autoTitle($conversation);
            }

            $this->addFlash('success', 'New conversation created!');

            return $this->redirectToRoute('chat_view', [
                'id' => $conversation->getId(),
            ]);
        }

        return $this->render('conversation/new.html.twig', [
            'user' => $user,
            'currentRoute' => 'chat',
        ]);
    }

    /**
     * View a conversation (chat interface)
     */
    #[Route('/chat/{id}', name: 'chat_view')]
    #[IsGranted('ROLE_USER')]
    public function viewConversation(Conversation $conversation, Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        // Check if conversation belongs to the same user
        if ($conversation->getUserId() !== $user->getId()) {
            throw $this->createAccessDeniedException('You can only view your own conversations');
        }

        // Get messages
        $messages = $this->messageRepository->findByConversation($conversation->getId());
        
        // Sort by createdAt (oldest first)
        usort($messages, function($a, $b) {
            return $a->getCreatedAt() <=> $b->getCreatedAt();
        });

        // Get conversation metadata
        $summary = $this->conversationService->getSummary($conversation);
        $tags = $this->conversationService->retrieveTags($conversation);
        $statistics = $this->conversationService->getStatistics($conversation);

        // Get context information
        $context = $this->conversationService->getContext($conversation);

        // Get available agents (from capabilities)
        // In a real implementation, you would get this from a capability registry
        $availableAgents = $this->getAvailableAgents();

        return $this->render('conversation/view.html.twig', [
            'user' => $user,
            'conversation' => $conversation,
            'messages' => $messages,
            'summary' => $summary,
            'tags' => $tags,
            'statistics' => $statistics,
            'context' => $context,
            'availableAgents' => $availableAgents,
            'currentRoute' => 'chat',
        ]);
    }

    /**
     * Send a message in a conversation
     */
    #[Route('/chat/{id}/send', name: 'chat_send', methods: ['POST'])]
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
        $action = $request->request->get('action');

        if (empty($content)) {
            return new JsonResponse(['error' => 'Message content is required'], 400);
        }

        // Add user message
        $message = $this->conversationService->addUserMessage($conversation, $content);

        // Check token budget
        $budgetCheck = $this->conversationService->checkTokenBudget($conversation, $content);
        
        if ($budgetCheck['exceeds']) {
            // Add warning message
            $this->conversationService->addAssistantMessage(
                $conversation,
                sprintf(
                    'Warning: This conversation has reached %d/%d tokens. Consider starting a new conversation.',
                    $budgetCheck['currentTokens'],
                    $budgetCheck['budget']
                )
            );
        }

        // If an agent or action is specified, execute it
        if (!empty($agentName) || !empty($action)) {
            $executionId = $this->executeAgentOrAction(
                $conversation,
                $user,
                $agentName,
                $action,
                $content
            );
        }

        // Auto-title the conversation if it's new
        if ($conversation->getMessageCount() === 1) {
            $this->conversationService->autoTitle($conversation);
        }

        return new JsonResponse([
            'status' => 'success',
            'messageId' => $message->getId(),
            'executionId' => $executionId ?? null,
            'message' => [
                'id' => $message->getId(),
                'role' => $message->getRole(),
                'content' => $message->getContent(),
                'createdAt' => $message->getCreatedAt()->format('c'),
                'tokenCount' => $message->getTokenCount(),
            ],
        ]);
    }

    /**
     * Execute an agent or action
     */
    private function executeAgentOrAction(
        Conversation $conversation,
        User $user,
        ?string $agentName,
        ?string $action,
        string $content
    ): ?string {
        $tenant = $conversation->getTenant();

        // Determine what to execute
        $executeName = $agentName ?: $action;
        
        if (empty($executeName)) {
            return null;
        }

        // Check if execution is allowed
        $effect = $this->securityGuard->evaluate(
            $tenant,
            'agent:execute',
            null,
            ['agentName' => $executeName, 'action' => $action]
        );

        if ($effect === 'deny') {
            $this->conversationService->addAssistantMessage(
                $conversation,
                sprintf('Sorry, I cannot execute "%s" due to security policies.', $executeName)
            );
            return null;
        }

        if ($effect === 'ask') {
            // Request approval
            $approvalId = $this->securityGuard->requestApproval(
                $tenant,
                $user,
                'agent:execute',
                null,
                ['agentName' => $executeName, 'action' => $action, 'conversationId' => $conversation->getId()]
            );
            
            $this->conversationService->addAssistantMessage(
                $conversation,
                sprintf(
                    'Approval required for "%s". Approval ID: %s. Please wait for an administrator to approve this action.',
                    $executeName,
                    substr($approvalId, 0, 8)
                )
            );
            return null;
        }

        // Execute agent
        $executionId = Ulid::generate();
        
        $agentMessage = new ExecuteAgentMessage(
            executionId: $executionId,
            userId: $user->getId(),
            tenantId: $tenant->getId(),
            agentName: $executeName,
            conversationId: $conversation->getId(),
            parameters: [
                'input' => $content,
                'action' => $action,
            ],
            metadata: [
                'source' => 'conversation',
                'messageId' => $this->messageRepository->findOneByConversationAndUser(
                    $conversation->getId(),
                    $user->getId()
                )?->getId(),
            ]
        );

        $this->messageBus->dispatch($agentMessage);

        // Add pending message
        $this->conversationService->addAssistantMessage(
            $conversation,
            sprintf('Executing "%s"... (ID: %s)', $executeName, substr($executionId, 0, 8))
        );

        return $executionId;
    }

    /**
     * Stream messages from a conversation (for real-time updates)
     */
    #[Route('/chat/{id}/stream', name: 'chat_stream')]
    #[IsGranted('ROLE_USER')]
    public function streamConversation(Conversation $conversation, Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        // Check if conversation belongs to the same user
        if ($conversation->getUserId() !== $user->getId()) {
            return new JsonResponse(['error' => 'Access denied'], 403);
        }

        // For now, return the current messages
        // In a real implementation, you would use:
        // 1. Server-Sent Events (SSE)
        // 2. WebSockets
        // 3. Polling with last message ID

        $lastMessageId = $request->query->get('last_message_id');
        
        $query = ['conversation' => $conversation];
        
        if ($lastMessageId) {
            $lastMessage = $this->messageRepository->find($lastMessageId);
            if ($lastMessage) {
                $query['createdAt'] = ['after' => $lastMessage->getCreatedAt()];
            }
        }

        $messages = $this->messageRepository->findBy($query, ['createdAt' => 'ASC']);

        $responseMessages = [];
        foreach ($messages as $message) {
            $responseMessages[] = [
                'id' => $message->getId(),
                'role' => $message->getRole(),
                'content' => $message->getContent(),
                'createdAt' => $message->getCreatedAt()->format('c'),
                'tokenCount' => $message->getTokenCount(),
            ];
        }

        return new JsonResponse([
            'messages' => $responseMessages,
            'lastMessageId' => end($messages)?->getId(),
        ]);
    }

    /**
     * Get conversation context
     */
    #[Route('/chat/{id}/context', name: 'chat_context')]
    #[IsGranted('ROLE_USER')]
    public function getContext(Conversation $conversation): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        // Check if conversation belongs to the same user
        if ($conversation->getUserId() !== $user->getId()) {
            return new JsonResponse(['error' => 'Access denied'], 403);
        }

        $context = $this->conversationService->getContext($conversation, [
            'windowSize' => 20,
            'includeSystem' => false,
        ]);

        return new JsonResponse($context);
    }

    /**
     * Get conversation summary
     */
    #[Route('/chat/{id}/summary', name: 'chat_summary')]
    #[IsGranted('ROLE_USER')]
    public function getSummary(Conversation $conversation): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        // Check if conversation belongs to the same user
        if ($conversation->getUserId() !== $user->getId()) {
            return new JsonResponse(['error' => 'Access denied'], 403);
        }

        $summaries = $this->conversationService->getAllSummaries($conversation, true);

        return new JsonResponse([
            'summaries' => $summaries,
        ]);
    }

    /**
     * Rename a conversation
     */
    #[Route('/chat/{id}/rename', name: 'chat_rename', methods: ['POST'])]
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
     * Delete a conversation
     */
    #[Route('/chat/{id}/delete', name: 'chat_delete', methods: ['POST'])]
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

        return $this->redirectToRoute('chat_index');
    }

    /**
     * Clear conversation context (remove old messages)
     */
    #[Route('/chat/{id}/clear', name: 'chat_clear', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function clearConversation(Conversation $conversation, Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        // Check if conversation belongs to the same user
        if ($conversation->getUserId() !== $user->getId()) {
            return new JsonResponse(['error' => 'Access denied'], 403);
        }

        $keepMessages = $request->request->getInt('keep_messages', 10);
        
        $removedCount = $this->conversationService->clearContext($conversation, $keepMessages);

        return new JsonResponse([
            'status' => 'success',
            'removedCount' => $removedCount,
            'keptCount' => $conversation->getMessageCount(),
        ]);
    }

    /**
     * Add a tag to a conversation
     */
    #[Route('/chat/{id}/tag', name: 'chat_tag', methods: ['POST'])]
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
            'tags' => $this->conversationService->retrieveTags($conversation),
        ]);
    }

    /**
     * Remove a tag from a conversation
     */
    #[Route('/chat/{id}/untag', name: 'chat_untag', methods: ['POST'])]
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
            'tags' => $this->conversationService->retrieveTags($conversation),
        ]);
    }

    /**
     * Get available agents
     */
    private function getAvailableAgents(): array
    {
        // In a real implementation, you would get this from a capability registry
        // or from a configuration file
        return [
            [
                'name' => 'assistant',
                'displayName' => 'AI Assistant',
                'description' => 'General AI assistant for answering questions',
                'category' => 'general',
                'capabilities' => ['answer_questions', 'provide_information', 'explain_concepts'],
            ],
            [
                'name' => 'summarizer',
                'displayName' => 'Summarizer',
                'description' => 'Summarize text and documents',
                'category' => 'text',
                'capabilities' => ['summarize', 'extract_key_points'],
            ],
            [
                'name' => 'translator',
                'displayName' => 'Translator',
                'description' => 'Translate text between languages',
                'category' => 'text',
                'capabilities' => ['translate'],
            ],
            [
                'name' => 'code_assistant',
                'displayName' => 'Code Assistant',
                'description' => 'Help with coding tasks',
                'category' => 'development',
                'capabilities' => ['write_code', 'explain_code', 'debug_code'],
            ],
            [
                'name' => 'email_assistant',
                'displayName' => 'Email Assistant',
                'description' => 'Help with email management',
                'category' => 'productivity',
                'capabilities' => ['draft_email', 'summarize_email'],
                'requiresIntegration' => 'microsoft_graph',
            ],
            [
                'name' => 'github_assistant',
                'displayName' => 'GitHub Assistant',
                'description' => 'Help with GitHub tasks',
                'category' => 'development',
                'capabilities' => ['create_issue', 'list_issues', 'search_code'],
                'requiresIntegration' => 'github_api',
            ],
            [
                'name' => 'web_researcher',
                'displayName' => 'Web Researcher',
                'description' => 'Research information on the web',
                'category' => 'research',
                'capabilities' => ['search_web', 'extract_information'],
                'requiresIntegration' => 'playwright',
            ],
            [
                'name' => 'file_manager',
                'displayName' => 'File Manager',
                'description' => 'Manage files and documents',
                'category' => 'productivity',
                'capabilities' => ['list_files', 'read_file', 'write_file'],
            ],
        ];
    }
}
