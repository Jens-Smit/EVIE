<?php

namespace App\Service\AI;

use App\Entity\AI\Conversation;
use App\Entity\AI\Message;
use App\Repository\AI\ConversationRepository;
use App\Repository\AI\MessageRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ContextManager manages conversation context for AI interactions.
 * 
 * Features:
 * - Context window management
 * - Message history retrieval
 * - Context summarization
 * - Context trimming
 * - Token counting
 */
class ContextManager
{
    private const int DEFAULT_CONTEXT_WINDOW_SIZE = 10;
    private const int DEFAULT_MAX_TOKENS = 4096;
    private const int TOKENS_PER_MESSAGE_ESTIMATE = 256;

    public function __construct(
        private ConversationRepository $conversationRepository,
        private MessageRepository $messageRepository,
        private EntityManagerInterface $entityManager,
        private int $contextWindowSize = self::DEFAULT_CONTEXT_WINDOW_SIZE,
        private int $maxTokens = self::DEFAULT_MAX_TOKENS
    ) {
    }

    /**
     * Get the context for a conversation.
     * 
     * @param Conversation $conversation The conversation
     * @param array $options Options (windowSize, maxTokens, includeSystem)
     * @return array Context array with messages
     */
    public function getContext(Conversation $conversation, array $options = []): array
    {
        $windowSize = $options['windowSize'] ?? $this->contextWindowSize;
        $maxTokens = $options['maxTokens'] ?? $this->maxTokens;
        $includeSystem = $options['includeSystem'] ?? true;

        // Get messages for this conversation
        $messages = $this->messageRepository->findByConversation($conversation->getId());

        // Sort by createdAt (oldest first)
        usort($messages, function(Message $a, Message $b) {
            return $a->getCreatedAt() <=> $b->getCreatedAt();
        });

        // Filter and trim context
        $context = $this->trimContext($messages, $windowSize, $maxTokens, $includeSystem);

        return [
            'conversationId' => $conversation->getId(),
            'conversationTitle' => $conversation->getTitle(),
            'messages' => $context['messages'],
            'tokenCount' => $context['tokenCount'],
            'messageCount' => $context['messageCount'],
            'hasMore' => $context['hasMore'],
        ];
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
        $context = $this->getContext($conversation, $options);
        
        $parts = [];
        foreach ($context['messages'] as $message) {
            $role = $message['role'];
            $content = $message['content'];
            
            // Format message for LLM
            $parts[] = sprintf("%s: %s", ucfirst($role), $content);
        }

        return implode("\n", $parts);
    }

    /**
     * Get the context as an array of message arrays for LLM API.
     * 
     * @param Conversation $conversation The conversation
     * @param array $options Options
     * @return array Array of message arrays (role, content)
     */
    public function getContextMessages(Conversation $conversation, array $options = []): array
    {
        $context = $this->getContext($conversation, $options);
        
        $messages = [];
        foreach ($context['messages'] as $message) {
            $messages[] = [
                'role' => $message['role'],
                'content' => $message['content'],
            ];
        }

        return $messages;
    }

    /**
     * Trim the context to fit within the specified limits.
     * 
     * @param Message[] $messages Array of messages
     * @param int $windowSize Maximum number of messages
     * @param int $maxTokens Maximum number of tokens
     * @param bool $includeSystem Whether to include system messages
     * @return array Trimmed context with metadata
     */
    private function trimContext(
        array $messages,
        int $windowSize,
        int $maxTokens,
        bool $includeSystem
    ): array {
        $filteredMessages = [];
        $tokenCount = 0;
        $hasMore = false;

        // Filter messages
        foreach ($messages as $message) {
            // Skip system messages if not included
            if (!$includeSystem && $message->getRole() === 'system') {
                continue;
            }

            $filteredMessages[] = $message;
        }

        // Sort by createdAt (newest first for trimming from the beginning)
        usort($filteredMessages, function(Message $a, Message $b) {
            return $b->getCreatedAt() <=> $a->getCreatedAt();
        });

        // Trim by window size and token count
        $trimmedMessages = [];
        $currentTokenCount = 0;

        foreach ($filteredMessages as $message) {
            $messageTokenCount = $message->getTokenCount();
            
            // If we don't have token count, estimate it
            if ($messageTokenCount === 0) {
                $messageTokenCount = $this->estimateTokenCount($message->getContent());
            }

            // Check if adding this message would exceed limits
            if (count($trimmedMessages) >= $windowSize || 
                $currentTokenCount + $messageTokenCount > $maxTokens) {
                $hasMore = true;
                break;
            }

            $trimmedMessages[] = $message;
            $currentTokenCount += $messageTokenCount;
        }

        // Sort back to oldest first
        usort($trimmedMessages, function(Message $a, Message $b) {
            return $a->getCreatedAt() <=> $b->getCreatedAt();
        });

        // Convert to array format
        $messageArray = [];
        foreach ($trimmedMessages as $message) {
            $messageArray[] = [
                'id' => $message->getId(),
                'role' => $message->getRole(),
                'content' => $message->getContent(),
                'createdAt' => $message->getCreatedAt()->format('c'),
                'tokenCount' => $message->getTokenCount(),
            ];
        }

        return [
            'messages' => $messageArray,
            'tokenCount' => $currentTokenCount,
            'messageCount' => count($messageArray),
            'hasMore' => $hasMore,
        ];
    }

    /**
     * Estimate the token count for a message content.
     * 
     * @param string $content The message content
     * @return int Estimated token count
     */
    public function estimateTokenCount(string $content): int
    {
        // Simple estimation: 1 token ≈ 4 characters (English)
        // For German, it might be slightly different, but this is a rough estimate
        $charCount = strlen($content);
        return (int)ceil($charCount / 4);
    }

    /**
     * Count the actual tokens in a message content.
     * 
     * Note: This requires a tokenization library. For now, we'll use the estimate.
     * 
     * @param string $content The message content
     * @return int Actual token count
     */
    public function countTokens(string $content): int
    {
        // In a real implementation, you would use a proper tokenizer
        // For example, with the Symfony AI Bundle or a dedicated tokenization library
        
        // For now, use the estimate
        return $this->estimateTokenCount($content);
    }

    /**
     * Get the token count for a conversation.
     * 
     * @param Conversation $conversation The conversation
     * @return int Total token count
     */
    public function getConversationTokenCount(Conversation $conversation): int
    {
        $messages = $this->messageRepository->findByConversation($conversation->getId());
        
        $total = 0;
        foreach ($messages as $message) {
            $tokenCount = $message->getTokenCount();
            if ($tokenCount === 0) {
                $tokenCount = $this->estimateTokenCount($message->getContent());
            }
            $total += $tokenCount;
        }

        return $total;
    }

    /**
     * Update the token count for a message.
     * 
     * @param Message $message The message
     * @param int $tokenCount The token count
     * @return Message The updated message
     */
    public function updateMessageTokenCount(Message $message, int $tokenCount): Message
    {
        $message->setTokenCount($tokenCount);
        $this->entityManager->persist($message);
        $this->entityManager->flush();
        
        return $message;
    }

    /**
     * Update the token count for a conversation.
     * 
     * @param Conversation $conversation The conversation
     * @return Conversation The updated conversation
     */
    public function updateConversationTokenCount(Conversation $conversation): Conversation
    {
        $tokenCount = $this->getConversationTokenCount($conversation);
        $conversation->setTokenCount($tokenCount);
        $this->entityManager->persist($conversation);
        $this->entityManager->flush();
        
        return $conversation;
    }

    /**
     * Get the context window size.
     * 
     * @return int
     */
    public function getContextWindowSize(): int
    {
        return $this->contextWindowSize;
    }

    /**
     * Set the context window size.
     * 
     * @param int $size The window size
     * @return self
     */
    public function setContextWindowSize(int $size): self
    {
        $this->contextWindowSize = $size;
        return $this;
    }

    /**
     * Get the maximum token count.
     * 
     * @return int
     */
    public function getMaxTokens(): int
    {
        return $this->maxTokens;
    }

    /**
     * Set the maximum token count.
     * 
     * @param int $maxTokens The maximum token count
     * @return self
     */
    public function setMaxTokens(int $maxTokens): self
    {
        $this->maxTokens = $maxTokens;
        return $this;
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
        $messages = $this->messageRepository->findByConversation($conversation->getId());
        
        // Sort by createdAt (oldest first)
        usort($messages, function(Message $a, Message $b) {
            return $a->getCreatedAt() <=> $b->getCreatedAt();
        });

        $removedCount = 0;
        
        // Remove old messages
        while (count($messages) > $keepMessages) {
            $oldMessage = array_shift($messages);
            $this->entityManager->remove($oldMessage);
            $removedCount++;
        }

        if ($removedCount > 0) {
            $this->entityManager->flush();
            
            // Update conversation message count
            $conversation->setMessageCount($conversation->getMessageCount() - $removedCount);
            $this->entityManager->persist($conversation);
            $this->entityManager->flush();
        }

        return $removedCount;
    }

    /**
     * Get the most recent messages for a conversation.
     * 
     * @param Conversation $conversation The conversation
     * @param int $limit Maximum number of messages to return
     * @return Message[]
     */
    public function getRecentMessages(Conversation $conversation, int $limit = 10): array
    {
        $messages = $this->messageRepository->findByConversation($conversation->getId());
        
        // Sort by createdAt (newest first)
        usort($messages, function(Message $a, Message $b) {
            return $b->getCreatedAt() <=> $a->getCreatedAt();
        });

        return array_slice($messages, 0, $limit);
    }

    /**
     * Get the first message in a conversation.
     * 
     * @param Conversation $conversation The conversation
     * @return Message|null
     */
    public function getFirstMessage(Conversation $conversation): ?Message
    {
        $messages = $this->messageRepository->findByConversation($conversation->getId());
        
        if (empty($messages)) {
            return null;
        }

        // Sort by createdAt (oldest first)
        usort($messages, function(Message $a, Message $b) {
            return $a->getCreatedAt() <=> $b->getCreatedAt();
        });

        return $messages[0];
    }

    /**
     * Get the last message in a conversation.
     * 
     * @param Conversation $conversation The conversation
     * @return Message|null
     */
    public function getLastMessage(Conversation $conversation): ?Message
    {
        $messages = $this->messageRepository->findByConversation($conversation->getId());
        
        if (empty($messages)) {
            return null;
        }

        // Sort by createdAt (newest first)
        usort($messages, function(Message $a, Message $b) {
            return $b->getCreatedAt() <=> $a->getCreatedAt();
        });

        return $messages[0];
    }
}
