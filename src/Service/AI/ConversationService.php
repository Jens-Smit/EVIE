<?php

namespace App\Service\AI;

use App\Entity\AI\Conversation;
use App\Entity\AI\Message;
use App\Entity\Tenant\User;
use App\Repository\AI\ConversationRepository;
use App\Repository\AI\MessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Ulid;

/**
 * ConversationService is a facade service for conversation operations.
 * 
 * This service provides a unified interface for:
 * - Creating and managing conversations
 * - Adding messages to conversations
 * - Managing conversation context
 * - Handling token usage
 * - Generating summaries
 * - Managing conversation memory
 * 
 * It delegates to specialized services (ContextManager, TokenManager, etc.)
 * for specific functionality.
 */
class ConversationService
{
    public function __construct(
        private ConversationRepository $conversationRepository,
        private MessageRepository $messageRepository,
        private EntityManagerInterface $entityManager,
        private ContextManager $contextManager,
        private TokenManager $tokenManager,
        private ConversationSummaryService $summaryService,
        private ConversationMemoryService $memoryService
    ) {
    }

    /**
     * Create a new conversation.
     * 
     * @param User $user The user who owns the conversation
     * @param string|null $title The conversation title
     * @param array $metadata Additional metadata
     * @return Conversation The created conversation
     */
    public function createConversation(
        User $user,
        ?string $title = null,
        array $metadata = []
    ): Conversation {
        $conversation = new Conversation();
        $conversation->setUser($user);
        $conversation->setTenant($user->getTenant());
        
        if ($title !== null) {
            $conversation->setTitle($title);
        } else {
            // Auto-generate title later
            $conversation->setTitle('New Conversation');
        }

        if (!empty($metadata)) {
            $conversation->setMetadata($metadata);
        }

        $this->entityManager->persist($conversation);
        $this->entityManager->flush();

        return $conversation;
    }

    /**
     * Find a conversation by ID.
     * 
     * @param string $id The conversation ID
     * @return Conversation|null The conversation or null if not found
     */
    public function findConversation(string $id): ?Conversation
    {
        return $this->conversationRepository->find($id);
    }

    /**
     * Find a conversation by ID with tenant isolation check.
     * 
     * @param string $id The conversation ID
     * @param string $tenantId The tenant ID
     * @return Conversation|null The conversation or null if not found or not in tenant
     */
    public function findConversationByIdAndTenant(string $id, string $tenantId): ?Conversation
    {
        return $this->conversationRepository->findOneByIdAndTenant($id, $tenantId);
    }

    /**
     * Find a conversation by ID with user isolation check.
     * 
     * @param string $id The conversation ID
     * @param string $userId The user ID
     * @return Conversation|null The conversation or null if not found or not for user
     */
    public function findConversationByIdAndUser(string $id, string $userId): ?Conversation
    {
        return $this->conversationRepository->findOneByIdAndUser($id, $userId);
    }

    /**
     * Get all conversations for a user.
     * 
     * @param User $user The user
     * @param array $options Options (status, limit, offset)
     * @return Conversation[]
     */
    public function getUserConversations(User $user, array $options = []): array
    {
        $conversations = $this->conversationRepository->findByUser($user->getId());
        
        // Apply filters
        if (isset($options['status'])) {
            $conversations = array_filter($conversations, function(Conversation $c) use ($options) {
                return $c->getStatus() === $options['status'];
            });
        }

        // Apply limit and offset
        if (isset($options['limit'])) {
            $conversations = array_slice($conversations, $options['offset'] ?? 0, $options['limit']);
        }

        return $conversations;
    }

    /**
     * Get active conversations for a user.
     * 
     * @param User $user The user
     * @return Conversation[]
     */
    public function getActiveConversations(User $user): array
    {
        return $this->conversationRepository->findActiveByUser($user->getId());
    }

    /**
     * Get the last conversation for a user.
     * 
     * @param User $user The user
     * @return Conversation|null
     */
    public function getLastConversation(User $user): ?Conversation
    {
        return $user->getLastConversation();
    }

    /**
     * Add a message to a conversation.
     * 
     * @param Conversation $conversation The conversation
     * @param string $role The message role (user, assistant, system, tool)
     * @param string $content The message content
     * @param array $metadata Additional metadata
     * @return Message The created message
     */
    public function addMessage(
        Conversation $conversation,
        string $role,
        string $content,
        array $metadata = []
    ): Message {
        $message = new Message();
        $message->setConversation($conversation);
        $message->setUser($conversation->getUser());
        $message->setRole($role);
        $message->setContent($content);
        
        if (!empty($metadata)) {
            $message->setMetadata($metadata);
        }

        // Count tokens
        $tokenCount = $this->tokenManager->countTokens($content);
        $message->setTokenCount($tokenCount);

        $this->entityManager->persist($message);
        $this->entityManager->flush();

        // Update conversation
        $conversation->incrementMessageCount();
        $conversation->setLastMessageAt(new \DateTimeImmutable());
        $conversation->addTokenCount($tokenCount);
        $this->entityManager->persist($conversation);
        $this->entityManager->flush();

        return $message;
    }

    /**
     * Add a user message to a conversation.
     * 
     * @param Conversation $conversation The conversation
     * @param string $content The message content
     * @param array $metadata Additional metadata
     * @return Message The created message
     */
    public function addUserMessage(
        Conversation $conversation,
        string $content,
        array $metadata = []
    ): Message {
        return $this->addMessage($conversation, 'user', $content, $metadata);
    }

    /**
     * Add an assistant message to a conversation.
     * 
     * @param Conversation $conversation The conversation
     * @param string $content The message content
     * @param array $metadata Additional metadata
     * @return Message The created message
     */
    public function addAssistantMessage(
        Conversation $conversation,
        string $content,
        array $metadata = []
    ): Message {
        return $this->addMessage($conversation, 'assistant', $content, $metadata);
    }

    /**
     * Add a system message to a conversation.
     * 
     * @param Conversation $conversation The conversation
     * @param string $content The message content
     * @param array $metadata Additional metadata
     * @return Message The created message
     */
    public function addSystemMessage(
        Conversation $conversation,
        string $content,
        array $metadata = []
    ): Message {
        return $this->addMessage($conversation, 'system', $content, $metadata);
    }

    /**
     * Add a tool message to a conversation.
     * 
     * @param Conversation $conversation The conversation
     * @param string $content The message content
     * @param array $metadata Additional metadata
     * @return Message The created message
     */
    public function addToolMessage(
        Conversation $conversation,
        string $content,
        array $metadata = []
    ): Message {
        return $this->addMessage($conversation, 'tool', $content, $metadata);
    }

    /**
     * Get the context for a conversation.
     * 
     * @param Conversation $conversation The conversation
     * @param array $options Options (windowSize, maxTokens, includeSystem)
     * @return array Context array
     */
    public function getContext(Conversation $conversation, array $options = []): array
    {
        return $this->contextManager->getContext($conversation, $options);
    }

    /**
     * Get the context as messages for LLM API.
     * 
     * @param Conversation $conversation The conversation
     * @param array $options Options
     * @return array Array of message arrays
     */
    public function getContextMessages(Conversation $conversation, array $options = []): array
    {
        return $this->contextManager->getContextMessages($conversation, $options);
    }

    /**
     * Get the context as a string for LLM input.
     * 
     * @param Conversation $conversation The conversation
     * @param array $options Options
     * @return string Formatted context string
     */
    public function getContextString(Conversation $conversation, array $options = []): string
    {
        return $this->contextManager->getContextString($conversation, $options);
    }

    /**
     * Check if adding a message would exceed the token budget.
     * 
     * @param Conversation $conversation The conversation
     * @param string $content The message content
     * @param string|null $model The model name
     * @return array Result with exceeds flag and token counts
     */
    public function checkTokenBudget(
        Conversation $conversation,
        string $content,
        ?string $model = null
    ): array {
        return $this->tokenManager->checkConversationTokenBudget(
            $conversation,
            $content,
            $model
        );
    }

    /**
     * Generate a summary for a conversation.
     * 
     * @param Conversation $conversation The conversation
     * @param string $type Summary type (short, medium, long)
     * @param array $options Options
     * @return string The generated summary
     */
    public function generateSummary(
        Conversation $conversation,
        string $type = 'medium',
        array $options = []
    ): string {
        return $this->summaryService->generateSummary($conversation, $type, $options);
    }

    /**
     * Get a summary for a conversation.
     * 
     * @param Conversation $conversation The conversation
     * @param string $type Summary type (short, medium, long)
     * @param bool $regenerate Whether to regenerate if not exists
     * @return string|null The summary or null if not available
     */
    public function getSummary(
        Conversation $conversation,
        string $type = 'medium',
        bool $regenerate = false
    ): ?string {
        return $this->summaryService->getSummary($conversation, $type, $regenerate);
    }

    /**
     * Update all summaries for a conversation.
     * 
     * @param Conversation $conversation The conversation
     * @return array Updated summaries
     */
    public function updateAllSummaries(Conversation $conversation): array
    {
        return $this->summaryService->updateAllSummaries($conversation);
    }

    /**
     * Auto-title a conversation.
     * 
     * @param Conversation $conversation The conversation
     * @param array $options Options
     * @return Conversation The updated conversation
     */
    public function autoTitle(Conversation $conversation, array $options = []): Conversation
    {
        return $this->summaryService->autoTitle($conversation, $options);
    }

    /**
     * Store memory for a conversation.
     * 
     * @param Conversation $conversation The conversation
     * @param string $key The memory key
     * @param mixed $value The memory value
     * @param string $type Memory type
     * @return Conversation The updated conversation
     */
    public function storeMemory(
        Conversation $conversation,
        string $key,
        mixed $value,
        string $type = 'conversation'
    ): Conversation {
        return $this->memoryService->storeMemory($conversation, $key, $value, $type);
    }

    /**
     * Retrieve memory for a conversation.
     * 
     * @param Conversation $conversation The conversation
     * @param string $key The memory key
     * @param string $type Memory type
     * @param mixed $default Default value if not found
     * @return mixed The memory value or default
     */
    public function retrieveMemory(
        Conversation $conversation,
        string $key,
        string $type = 'conversation',
        mixed $default = null
    ): mixed {
        return $this->memoryService->retrieveMemory($conversation, $key, $type, $default);
    }

    /**
     * Store conversation state.
     * 
     * @param Conversation $conversation The conversation
     * @param array $state The state to store
     * @return Conversation The updated conversation
     */
    public function storeState(Conversation $conversation, array $state): Conversation
    {
        return $this->memoryService->storeState($conversation, $state);
    }

    /**
     * Retrieve conversation state.
     * 
     * @param Conversation $conversation The conversation
     * @return array The state or empty array
     */
    public function retrieveState(Conversation $conversation): array
    {
        return $this->memoryService->retrieveState($conversation);
    }

    /**
     * Add a tag to a conversation.
     * 
     * @param Conversation $conversation The conversation
     * @param string $tag The tag to add
     * @return Conversation The updated conversation
     */
    public function addTag(Conversation $conversation, string $tag): Conversation
    {
        return $this->memoryService->addTag($conversation, $tag);
    }

    /**
     * Remove a tag from a conversation.
     * 
     * @param Conversation $conversation The conversation
     * @param string $tag The tag to remove
     * @return Conversation The updated conversation
     */
    public function removeTag(Conversation $conversation, string $tag): Conversation
    {
        return $this->memoryService->removeTag($conversation, $tag);
    }

    /**
     * Check if a conversation has a tag.
     * 
     * @param Conversation $conversation The conversation
     * @param string $tag The tag to check
     * @return bool
     */
    public function hasTag(Conversation $conversation, string $tag): bool
    {
        return $this->memoryService->hasTag($conversation, $tag);
    }

    /**
     * Get conversations by tag for a user.
     * 
     * @param User $user The user
     * @param string $tag The tag
     * @return Conversation[]
     */
    public function getConversationsByTag(User $user, string $tag): array
    {
        return $this->memoryService->getConversationsByTag($user, $tag);
    }

    /**
     * Archive a conversation.
     * 
     * @param Conversation $conversation The conversation
     * @return Conversation The updated conversation
     */
    public function archiveConversation(Conversation $conversation): Conversation
    {
        $conversation->archive();
        $this->entityManager->persist($conversation);
        $this->entityManager->flush();
        return $conversation;
    }

    /**
     * Pause a conversation.
     * 
     * @param Conversation $conversation The conversation
     * @return Conversation The updated conversation
     */
    public function pauseConversation(Conversation $conversation): Conversation
    {
        $conversation->pause();
        $this->entityManager->persist($conversation);
        $this->entityManager->flush();
        return $conversation;
    }

    /**
     * Continue a conversation.
     * 
     * @param Conversation $conversation The conversation
     * @return Conversation The updated conversation
     */
    public function continueConversation(Conversation $conversation): Conversation
    {
        $conversation->continue();
        $this->entityManager->persist($conversation);
        $this->entityManager->flush();
        return $conversation;
    }

    /**
     * Delete a conversation (soft delete).
     * 
     * @param Conversation $conversation The conversation
     * @return Conversation The updated conversation
     */
    public function deleteConversation(Conversation $conversation): Conversation
    {
        $conversation->delete();
        $this->entityManager->persist($conversation);
        $this->entityManager->flush();
        return $conversation;
    }

    /**
     * Rename a conversation.
     * 
     * @param Conversation $conversation The conversation
     * @param string $title The new title
     * @return Conversation The updated conversation
     */
    public function renameConversation(Conversation $conversation, string $title): Conversation
    {
        $conversation->setTitle($title);
        $this->entityManager->persist($conversation);
        $this->entityManager->flush();
        return $conversation;
    }

    /**
     * Clear the context for a conversation (remove old messages).
     * 
     * @param Conversation $conversation The conversation
     * @param int $keepMessages Number of messages to keep
     * @return int Number of messages removed
     */
    public function clearContext(Conversation $conversation, int $keepMessages = 10): int
    {
        return $this->contextManager->clearContext($conversation, $keepMessages);
    }

    /**
     * Get conversation statistics.
     * 
     * @param Conversation $conversation The conversation
     * @return array Statistics
     */
    public function getStatistics(Conversation $conversation): array
    {
        return [
            'id' => $conversation->getId(),
            'title' => $conversation->getTitle(),
            'status' => $conversation->getStatus(),
            'createdAt' => $conversation->getCreatedAt()->format('c'),
            'updatedAt' => $conversation->getUpdatedAt()->format('c'),
            'lastMessageAt' => $conversation->getLastMessageAt()?->format('c'),
            'messageCount' => $conversation->getMessageCount(),
            'tokenCount' => $conversation->getTokenCount(),
            'userId' => $conversation->getUserId(),
            'tenantId' => $conversation->getTenantId(),
        ];
    }

    /**
     * Get user conversation statistics.
     * 
     * @param User $user The user
     * @return array Statistics
     */
    public function getUserStatistics(User $user): array
    {
        return $this->tokenManager->getUserTokenStatistics($user);
    }

    /**
     * Search conversations by content.
     * 
     * @param User $user The user
     * @param string $query The search query
     * @return Conversation[]
     */
    public function searchConversations(User $user, string $query): array
    {
        return $this->conversationRepository->searchByTitle($user->getId(), $query);
    }

    /**
     * Get the first message in a conversation.
     * 
     * @param Conversation $conversation The conversation
     * @return Message|null
     */
    public function getFirstMessage(Conversation $conversation): ?Message
    {
        return $this->contextManager->getFirstMessage($conversation);
    }

    /**
     * Get the last message in a conversation.
     * 
     * @param Conversation $conversation The conversation
     * @return Message|null
     */
    public function getLastMessage(Conversation $conversation): ?Message
    {
        return $this->contextManager->getLastMessage($conversation);
    }

    /**
     * Get recent messages for a conversation.
     * 
     * @param Conversation $conversation The conversation
     * @param int $limit Maximum number of messages
     * @return Message[]
     */
    public function getRecentMessages(Conversation $conversation, int $limit = 10): array
    {
        return $this->contextManager->getRecentMessages($conversation, $limit);
    }
}
