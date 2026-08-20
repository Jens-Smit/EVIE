<?php

namespace App\Service\AI;

use App\Entity\AI\Conversation;
use App\Entity\AI\Message;
use App\Entity\Tenant\User;
use App\Repository\AI\ConversationRepository;
use App\Repository\AI\MessageRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * TokenManager manages token usage tracking for LLM interactions.
 * 
 * Features:
 * - Token counting for messages
 * - Token budget management per conversation
 * - Token usage tracking per user
 * - Token cost calculation
 * - Rate limiting based on token usage
 */
class TokenManager
{
    private const int DEFAULT_TOKEN_BUDGET = 100000; // 100k tokens per conversation
    private const int DEFAULT_MAX_TOKENS_PER_MESSAGE = 4096;
    private const float DEFAULT_TOKEN_COST = 0.00001; // $0.00001 per token (example)
    private const array TOKEN_PRICES = [
        'mistral-tiny' => 0.00000025,
        'mistral-small' => 0.0000007,
        'mistral-medium' => 0.0000027,
        'mistral-large' => 0.000008,
        'gpt-4o-mini' => 0.0000015,
        'gpt-4o' => 0.000005,
        'gpt-4' => 0.00003,
        'claud-3-haiku' => 0.0000008,
        'claud-3-sonnet' => 0.000003,
        'claud-3-opus' => 0.000015,
        'gemini-1.5-flash' => 0.00000035,
        'gemini-1.5-pro' => 0.0000025,
    ];

    public function __construct(
        private MessageRepository $messageRepository,
        private ConversationRepository $conversationRepository,
        private EntityManagerInterface $entityManager,
        private ContextManager $contextManager,
        private int $tokenBudget = self::DEFAULT_TOKEN_BUDGET,
        private int $maxTokensPerMessage = self::DEFAULT_MAX_TOKENS_PER_MESSAGE
    ) {
    }

    /**
     * Count tokens in a text using a simple estimation.
     * 
     * Note: For accurate token counting, you should use a proper tokenizer
     * that matches the tokenization of your LLM provider.
     * 
     * @param string $text The text to count
     * @param string|null $model The model name (for model-specific counting)
     * @return int Token count
     */
    public function countTokens(string $text, ?string $model = null): int
    {
        // Use ContextManager's estimation as base
        $count = $this->contextManager->countTokens($text);

        // If model is specified, adjust for model-specific tokenization
        if ($model !== null && isset($this->getTokenizationAdjustments()[$model])) {
            $adjustment = $this->getTokenizationAdjustments()[$model];
            $count = (int)($count * $adjustment);
        }

        return $count;
    }

    /**
     * Count tokens in an array of messages.
     * 
     * @param array $messages Array of messages (role, content)
     * @param string|null $model The model name
     * @return int Total token count
     */
    public function countMessageTokens(array $messages, ?string $model = null): int
    {
        $total = 0;
        
        foreach ($messages as $message) {
            $content = $message['content'] ?? '';
            $role = $message['role'] ?? '';
            
            // Count tokens in content
            $total += $this->countTokens($content, $model);
            
            // Count tokens in role (some models count role tokens)
            $total += $this->countTokens($role, $model);
        }

        return $total;
    }

    /**
     * Update token count for a message.
     * 
     * @param Message $message The message
     * @param string $content The content (if different from message content)
     * @param string|null $model The model name
     * @return Message The updated message
     */
    public function updateMessageTokenCount(
        Message $message,
        ?string $content = null,
        ?string $model = null
    ): Message {
        $text = $content ?? $message->getContent();
        $tokenCount = $this->countTokens($text, $model);
        
        $message->setTokenCount($tokenCount);
        $this->entityManager->persist($message);
        $this->entityManager->flush();
        
        return $message;
    }

    /**
     * Update token count for all messages in a conversation.
     * 
     * @param Conversation $conversation The conversation
     * @param string|null $model The model name
     * @return Conversation The updated conversation
     */
    public function updateConversationTokenCounts(
        Conversation $conversation,
        ?string $model = null
    ): Conversation {
        $messages = $this->messageRepository->findByConversation($conversation->getId());
        $totalTokens = 0;
        
        foreach ($messages as $message) {
            $tokenCount = $this->countTokens($message->getContent(), $model);
            $message->setTokenCount($tokenCount);
            $totalTokens += $tokenCount;
            $this->entityManager->persist($message);
        }

        $conversation->setTokenCount($totalTokens);
        $this->entityManager->persist($conversation);
        $this->entityManager->flush();
        
        return $conversation;
    }

    /**
     * Check if a message exceeds the token limit.
     * 
     * @param string $content The message content
     * @param string|null $model The model name
     * @return bool True if the message exceeds the limit
     */
    public function exceedsMessageTokenLimit(string $content, ?string $model = null): bool
    {
        $tokenCount = $this->countTokens($content, $model);
        return $tokenCount > $this->maxTokensPerMessage;
    }

    /**
     * Check if adding a message would exceed the conversation token budget.
     * 
     * @param Conversation $conversation The conversation
     * @param string $newMessageContent The new message content
     * @param string|null $model The model name
     * @return array Result with exceeds flag and current/max tokens
     */
    public function checkConversationTokenBudget(
        Conversation $conversation,
        string $newMessageContent,
        ?string $model = null
    ): array {
        $currentTokens = $conversation->getTokenCount();
        $newTokens = $this->countTokens($newMessageContent, $model);
        $totalTokens = $currentTokens + $newTokens;
        $budget = $this->tokenBudget;
        
        return [
            'exceeds' => $totalTokens > $budget,
            'currentTokens' => $currentTokens,
            'newTokens' => $newTokens,
            'totalTokens' => $totalTokens,
            'budget' => $budget,
            'remaining' => $budget - $currentTokens,
        ];
    }

    /**
     * Get token usage statistics for a user.
     * 
     * @param User $user The user
     * @return array Token usage statistics
     */
    public function getUserTokenStatistics(User $user): array
    {
        $conversations = $user->getConversations();
        
        $totalTokens = 0;
        $conversationCount = 0;
        $messageCount = 0;
        $conversationStats = [];

        foreach ($conversations as $conversation) {
            $convTokens = $conversation->getTokenCount();
            $totalTokens += $convTokens;
            $conversationCount++;
            
            $messages = $conversation->getMessages();
            $messageCount += count($messages);
            
            $conversationStats[] = [
                'conversationId' => $conversation->getId(),
                'title' => $conversation->getTitle(),
                'tokenCount' => $convTokens,
                'messageCount' => count($messages),
            ];
        }

        return [
            'userId' => $user->getId(),
            'totalTokens' => $totalTokens,
            'conversationCount' => $conversationCount,
            'messageCount' => $messageCount,
            'averageTokensPerConversation' => $conversationCount > 0 ? $totalTokens / $conversationCount : 0,
            'conversations' => $conversationStats,
        ];
    }

    /**
     * Get token usage statistics for a tenant.
     * 
     * @param string $tenantId The tenant ID
     * @return array Token usage statistics
     */
    public function getTenantTokenStatistics(string $tenantId): array
    {
        $conversations = $this->conversationRepository->findByTenant($tenantId);
        
        $totalTokens = 0;
        $userCount = 0;
        $conversationCount = 0;
        $messageCount = 0;
        $userStats = [];

        foreach ($conversations as $conversation) {
            $convTokens = $conversation->getTokenCount();
            $totalTokens += $convTokens;
            $conversationCount++;
            
            $messages = $conversation->getMessages();
            $messageCount += count($messages);
            
            $userId = $conversation->getUserId();
            if (!isset($userStats[$userId])) {
                $userStats[$userId] = [
                    'userId' => $userId,
                    'tokenCount' => 0,
                    'conversationCount' => 0,
                ];
                $userCount++;
            }
            
            $userStats[$userId]['tokenCount'] += $convTokens;
            $userStats[$userId]['conversationCount']++;
        }

        return [
            'tenantId' => $tenantId,
            'totalTokens' => $totalTokens,
            'userCount' => $userCount,
            'conversationCount' => $conversationCount,
            'messageCount' => $messageCount,
            'averageTokensPerConversation' => $conversationCount > 0 ? $totalTokens / $conversationCount : 0,
            'users' => array_values($userStats),
        ];
    }

    /**
     * Calculate the cost of token usage.
     * 
     * @param int $tokenCount The number of tokens
     * @param string $model The model name
     * @param bool $input True for input tokens, false for output tokens
     * @return float Cost in USD
     */
    public function calculateCost(int $tokenCount, string $model, bool $input = true): float
    {
        $pricePerToken = $this->getTokenPrice($model, $input);
        return $tokenCount * $pricePerToken;
    }

    /**
     * Calculate the cost of a conversation.
     * 
     * @param Conversation $conversation The conversation
     * @param string $model The model name
     * @return float Cost in USD
     */
    public function calculateConversationCost(Conversation $conversation, string $model): float
    {
        $messages = $this->messageRepository->findByConversation($conversation->getId());
        $cost = 0.0;
        
        foreach ($messages as $message) {
            $tokenCount = $message->getTokenCount();
            if ($tokenCount === 0) {
                $tokenCount = $this->countTokens($message->getContent(), $model);
            }
            
            // Assume all messages are input for now
            // In a real implementation, you would track input/output separately
            $cost += $this->calculateCost($tokenCount, $model, true);
        }

        return $cost;
    }

    /**
     * Get the token price for a model.
     * 
     * @param string $model The model name
     * @param bool $input True for input tokens, false for output tokens
     * @return float Price per token in USD
     */
    public function getTokenPrice(string $model, bool $input = true): float
    {
        // Normalize model name
        $model = strtolower($model);
        
        // Check if we have a specific price for this model
        foreach ($this->TOKEN_PRICES as $modelPattern => $price) {
            if (str_contains($model, $modelPattern)) {
                return $price;
            }
        }

        // Default price
        return $this->DEFAULT_TOKEN_COST;
    }

    /**
     * Get token price information for all models.
     * 
     * @return array Model price information
     */
    public function getModelPrices(): array
    {
        $prices = [];
        
        foreach ($this->TOKEN_PRICES as $model => $price) {
            $prices[$model] = [
                'inputPrice' => $price,
                'outputPrice' => $price * 2, // Output is typically more expensive
            ];
        }

        return $prices;
    }

    /**
     * Get tokenization adjustments for different models.
     * 
     * @return array Model-specific adjustments
     */
    private function getTokenizationAdjustments(): array
    {
        // Some models use different tokenization
        // This is a placeholder for model-specific adjustments
        return [
            'gpt-4' => 1.0,
            'gpt-3.5' => 1.0,
            'claud' => 1.0,
            'mistral' => 1.0,
            'gemini' => 1.0,
        ];
    }

    /**
     * Get the token budget.
     * 
     * @return int
     */
    public function getTokenBudget(): int
    {
        return $this->tokenBudget;
    }

    /**
     * Set the token budget.
     * 
     * @param int $budget The token budget
     * @return self
     */
    public function setTokenBudget(int $budget): self
    {
        $this->tokenBudget = $budget;
        return $this;
    }

    /**
     * Get the maximum tokens per message.
     * 
     * @return int
     */
    public function getMaxTokensPerMessage(): int
    {
        return $this->maxTokensPerMessage;
    }

    /**
     * Set the maximum tokens per message.
     * 
     * @param int $max The maximum tokens
     * @return self
     */
    public function setMaxTokensPerMessage(int $max): self
    {
        $this->maxTokensPerMessage = $max;
        return $this;
    }

    /**
     * Trim a message to fit within the token limit.
     * 
     * @param string $content The message content
     * @param int $maxTokens The maximum number of tokens
     * @param string|null $model The model name
     * @return string Trimmed content
     */
    public function trimMessage(string $content, int $maxTokens, ?string $model = null): string
    {
        $tokenCount = $this->countTokens($content, $model);
        
        if ($tokenCount <= $maxTokens) {
            return $content;
        }

        // Estimate characters per token
        $charsPerToken = strlen($content) / max(1, $tokenCount);
        $maxChars = (int)($maxTokens * $charsPerToken * 0.9); // 90% to be safe
        
        // Trim content
        $trimmed = substr($content, 0, $maxChars);
        
        // Add ellipsis if we trimmed
        if (strlen($trimmed) < strlen($content)) {
            $trimmed .= '...';
        }

        return $trimmed;
    }
}
