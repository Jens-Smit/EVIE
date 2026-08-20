<?php

namespace App\Service\AI;

use App\Entity\AI\Conversation;
use App\Entity\AI\Message;
use App\Repository\AI\ConversationRepository;
use App\Repository\AI\MessageRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ConversationSummaryService generates and manages conversation summaries.
 * 
 * Features:
 * - Automatic conversation summarization
 * - Summary storage and retrieval
 * - Summary updating
 * - Context-aware summarization
 * - Multi-level summaries (short, medium, long)
 */
class ConversationSummaryService
{
    private const string SUMMARY_SHORT = 'short';
    private const string SUMMARY_MEDIUM = 'medium';
    private const string SUMMARY_LONG = 'long';
    
    private const array SUMMARY_CONFIG = [
        self::SUMMARY_SHORT => [
            'max_tokens' => 128,
            'max_messages' => 5,
            'purpose' => 'Provide a brief summary of the conversation',
        ],
        self::SUMMARY_MEDIUM => [
            'max_tokens' => 512,
            'max_messages' => 20,
            'purpose' => 'Provide a detailed summary of the conversation',
        ],
        self::SUMMARY_LONG => [
            'max_tokens' => 2048,
            'max_messages' => 50,
            'purpose' => 'Provide a comprehensive summary of the conversation',
        ],
    ];

    public function __construct(
        private MessageRepository $messageRepository,
        private ConversationRepository $conversationRepository,
        private EntityManagerInterface $entityManager,
        private ContextManager $contextManager,
        private TokenManager $tokenManager
    ) {
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
        string $type = self::SUMMARY_MEDIUM,
        array $options = []
    ): string {
        $config = self::SUMMARY_CONFIG[$type] ?? self::SUMMARY_CONFIG[self::SUMMARY_MEDIUM];
        
        // Get messages for summarization
        $messages = $this->getMessagesForSummary($conversation, $config);
        
        // Generate summary based on messages
        $summary = $this->generateSummaryFromMessages($messages, $config, $options);
        
        // Store summary in conversation metadata
        $this->storeSummary($conversation, $type, $summary);
        
        return $summary;
    }

    /**
     * Get messages for summarization.
     * 
     * @param Conversation $conversation The conversation
     * @param array $config Summary configuration
     * @return Message[]
     */
    private function getMessagesForSummary(Conversation $conversation, array $config): array
    {
        $messages = $this->messageRepository->findByConversation($conversation->getId());
        
        // Sort by createdAt (oldest first)
        usort($messages, function(Message $a, Message $b) {
            return $a->getCreatedAt() <=> $b->getCreatedAt();
        });

        // Limit by max_messages
        $maxMessages = $config['max_messages'] ?? PHP_INT_MAX;
        if (count($messages) > $maxMessages) {
            // Keep first and last messages, and some from the middle
            $keptMessages = [];
            
            // Always keep first message
            $keptMessages[] = array_shift($messages);
            
            // Keep last message
            $lastMessage = array_pop($messages);
            
            // Keep some from the middle
            $middleCount = min($maxMessages - 2, count($messages));
            if ($middleCount > 0) {
                $step = (int)ceil(count($messages) / $middleCount);
                for ($i = 0; $i < $middleCount && !empty($messages); $i += $step) {
                    $keptMessages[] = $messages[$i] ?? end($messages);
                }
            }
            
            // Add last message
            if ($lastMessage !== null) {
                $keptMessages[] = $lastMessage;
            }
            
            $messages = $keptMessages;
        }

        return $messages;
    }

    /**
     * Generate a summary from messages.
     * 
     * @param Message[] $messages Array of messages
     * @param array $config Summary configuration
     * @param array $options Options
     * @return string The generated summary
     */
    private function generateSummaryFromMessages(
        array $messages,
        array $config,
        array $options
    ): string {
        // In a real implementation, you would:
        // 1. Format messages for LLM input
        // 2. Call an LLM with a summarization prompt
        // 3. Process the response
        
        // For now, we'll create a simple summary
        if (empty($messages)) {
            return 'No messages in this conversation.';
        }

        // Simple summary based on message content
        $summaryParts = [];
        $userMessages = [];
        $assistantMessages = [];

        foreach ($messages as $message) {
            $role = $message->getRole();
            $content = $message->getContent();
            
            if ($role === 'user') {
                $userMessages[] = $content;
            } elseif ($role === 'assistant') {
                $assistantMessages[] = $content;
            }
        }

        // Create summary
        $summary = '';
        
        // Add user messages summary
        if (!empty($userMessages)) {
            $userSummary = $this->summarizeTexts($userMessages, $config['max_tokens'] / 2);
            $summary .= "User asked: {$userSummary} ";
        }

        // Add assistant messages summary
        if (!empty($assistantMessages)) {
            $assistantSummary = $this->summarizeTexts($assistantMessages, $config['max_tokens'] / 2);
            $summary .= "Assistant responded: {$assistantSummary}";
        }

        // Trim to max tokens
        $tokenCount = $this->tokenManager->countTokens($summary);
        if ($tokenCount > $config['max_tokens']) {
            $summary = $this->tokenManager->trimMessage(
                $summary,
                $config['max_tokens']
            );
        }

        return $summary;
    }

    /**
     * Summarize multiple texts into a single summary.
     * 
     * @param string[] $texts Array of texts
     * @param int $maxTokens Maximum tokens for the summary
     * @return string The summary
     */
    private function summarizeTexts(array $texts, int $maxTokens): string
    {
        if (empty($texts)) {
            return '';
        }

        // Simple concatenation with separator
        $summary = implode('; ', array_slice($texts, 0, 3));
        
        // Trim to max tokens
        $tokenCount = $this->tokenManager->countTokens($summary);
        if ($tokenCount > $maxTokens) {
            $summary = $this->tokenManager->trimMessage($summary, $maxTokens);
        }

        return $summary;
    }

    /**
     * Store a summary in conversation metadata.
     * 
     * @param Conversation $conversation The conversation
     * @param string $type Summary type
     * @param string $summary The summary
     */
    private function storeSummary(Conversation $conversation, string $type, string $summary): void
    {
        $metadata = $conversation->getMetadata() ?? [];
        
        if (!isset($metadata['summaries'])) {
            $metadata['summaries'] = [];
        }

        $metadata['summaries'][$type] = [
            'summary' => $summary,
            'generatedAt' => (new \DateTimeImmutable())->format('c'),
            'tokenCount' => $this->tokenManager->countTokens($summary),
        ];

        $conversation->setMetadata($metadata);
        $this->entityManager->persist($conversation);
        $this->entityManager->flush();
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
        string $type = self::SUMMARY_MEDIUM,
        bool $regenerate = false
    ): ?string {
        $metadata = $conversation->getMetadata() ?? [];
        
        if (isset($metadata['summaries'][$type])) {
            return $metadata['summaries'][$type]['summary'];
        }

        // Regenerate if requested
        if ($regenerate) {
            return $this->generateSummary($conversation, $type);
        }

        return null;
    }

    /**
     * Get all summaries for a conversation.
     * 
     * @param Conversation $conversation The conversation
     * @param bool $regenerateMissing Whether to regenerate missing summaries
     * @return array All summaries
     */
    public function getAllSummaries(
        Conversation $conversation,
        bool $regenerateMissing = false
    ): array {
        $summaries = [];
        
        foreach (array_keys(self::SUMMARY_CONFIG) as $type) {
            $summary = $this->getSummary($conversation, $type, $regenerateMissing);
            if ($summary !== null) {
                $summaries[$type] = $summary;
            }
        }

        return $summaries;
    }

    /**
     * Update all summaries for a conversation.
     * 
     * @param Conversation $conversation The conversation
     * @return array Updated summaries
     */
    public function updateAllSummaries(Conversation $conversation): array
    {
        $summaries = [];
        
        foreach (array_keys(self::SUMMARY_CONFIG) as $type) {
            $summary = $this->generateSummary($conversation, $type);
            $summaries[$type] = $summary;
        }

        return $summaries;
    }

    /**
     * Generate a title for a conversation based on its content.
     * 
     * @param Conversation $conversation The conversation
     * @param array $options Options
     * @return string The generated title
     */
    public function generateTitle(Conversation $conversation, array $options = []): string
    {
        $messages = $this->messageRepository->findByConversation($conversation->getId());
        
        if (empty($messages)) {
            return 'New Conversation';
        }

        // Get the first user message
        $firstUserMessage = null;
        foreach ($messages as $message) {
            if ($message->getRole() === 'user') {
                $firstUserMessage = $message;
                break;
            }
        }

        if ($firstUserMessage === null) {
            return 'New Conversation';
        }

        $content = $firstUserMessage->getContent();
        
        // Extract title from content
        $title = $this->extractTitleFromContent($content);
        
        if ($title === null) {
            // Use first few words
            $words = preg_split('/\\s+/', $content);
            $title = implode(' ', array_slice($words, 0, 5));
        }

        // Limit length
        if (strlen($title) > 100) {
            $title = substr($title, 0, 97) . '...';
        }

        return $title;
    }

    /**
     * Extract a title from message content.
     * 
     * @param string $content The message content
     * @return string|null The extracted title or null
     */
    private function extractTitleFromContent(string $content): ?string
    {
        // Check for common title patterns
        $patterns = [
            '/^Title:\\s*(.+)$/im',
            '/^Subject:\\s*(.+)$/im',
            '/^Topic:\\s*(.+)$/im',
            '/^Question:\\s*(.+)$/im',
            '/^I need help with\\s*(.+)$/im',
            '/^How do I\\s*(.+)$/im',
            '/^What is\\s*(.+)$/im',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content, $matches)) {
                return trim($matches[1]);
            }
        }

        return null;
    }

    /**
     * Set the title for a conversation.
     * 
     * @param Conversation $conversation The conversation
     * @param string $title The title
     * @return Conversation The updated conversation
     */
    public function setTitle(Conversation $conversation, string $title): Conversation
    {
        $conversation->setTitle($title);
        $this->entityManager->persist($conversation);
        $this->entityManager->flush();
        
        return $conversation;
    }

    /**
     * Auto-title a conversation (generate and set title).
     * 
     * @param Conversation $conversation The conversation
     * @param array $options Options
     * @return Conversation The updated conversation
     */
    public function autoTitle(Conversation $conversation, array $options = []): Conversation
    {
        $title = $this->generateTitle($conversation, $options);
        return $this->setTitle($conversation, $title);
    }

    /**
     * Get summary statistics for a conversation.
     * 
     * @param Conversation $conversation The conversation
     * @return array Summary statistics
     */
    public function getSummaryStatistics(Conversation $conversation): array
    {
        $metadata = $conversation->getMetadata() ?? [];
        $summaries = $metadata['summaries'] ?? [];
        
        $stats = [
            'conversationId' => $conversation->getId(),
            'title' => $conversation->getTitle(),
            'messageCount' => $conversation->getMessageCount(),
            'tokenCount' => $conversation->getTokenCount(),
            'summaries' => [],
        ];

        foreach ($summaries as $type => $summaryData) {
            $stats['summaries'][$type] = [
                'tokenCount' => $summaryData['tokenCount'] ?? 0,
                'generatedAt' => $summaryData['generatedAt'] ?? null,
            ];
        }

        return $stats;
    }

    /**
     * Get summary types.
     * 
     * @return array
     */
    public function getSummaryTypes(): array
    {
        return array_keys(self::SUMMARY_CONFIG);
    }

    /**
     * Get summary configuration.
     * 
     * @param string $type Summary type
     * @return array Configuration
     */
    public function getSummaryConfig(string $type): array
    {
        return self::SUMMARY_CONFIG[$type] ?? self::SUMMARY_CONFIG[self::SUMMARY_MEDIUM];
    }
}
