<?php

namespace App\Service\AI;

use App\Entity\AI\Conversation;
use App\Entity\AI\Message;
use App\Entity\Tenant\User;
use App\Repository\AI\ConversationRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ConversationMemoryService manages conversation memory and history.
 * 
 * Features:
 * - Conversation history management
 * - Memory storage and retrieval
 * - Memory types (short-term, long-term)
 * - Memory context management
 * - Memory search and filtering
 */
class ConversationMemoryService
{
    private const string MEMORY_TYPE_SHORT_TERM = 'short_term';
    private const string MEMORY_TYPE_LONG_TERM = 'long_term';
    private const string MEMORY_TYPE_CONVERSATION = 'conversation';
    private const string MEMORY_TYPE_USER = 'user';
    private const string MEMORY_TYPE_ORGANIZATION = 'organization';

    public function __construct(
        private ConversationRepository $conversationRepository,
        private EntityManagerInterface $entityManager,
        private ContextManager $contextManager
    ) {
    }

    /**
     * Store memory for a conversation.
     * 
     * @param Conversation $conversation The conversation
     * @param string $key The memory key
     * @param mixed $value The memory value
     * @param string $type Memory type
     * @param array $metadata Additional metadata
     * @return Conversation The updated conversation
     */
    public function storeMemory(
        Conversation $conversation,
        string $key,
        mixed $value,
        string $type = self::MEMORY_TYPE_CONVERSATION,
        array $metadata = []
    ): Conversation {
        $metadata = $conversation->getMetadata() ?? [];
        
        if (!isset($metadata['memory'])) {
            $metadata['memory'] = [];
        }

        if (!isset($metadata['memory'][$type])) {
            $metadata['memory'][$type] = [];
        }

        $metadata['memory'][$type][$key] = [
            'value' => $this->serializeValue($value),
            'storedAt' => (new \DateTimeImmutable())->format('c'),
            'metadata' => $metadata,
        ];

        $conversation->setMetadata($metadata);
        $this->entityManager->persist($conversation);
        $this->entityManager->flush();

        return $conversation;
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
        string $type = self::MEMORY_TYPE_CONVERSATION,
        mixed $default = null
    ): mixed {
        $metadata = $conversation->getMetadata() ?? [];
        
        if (!isset($metadata['memory'][$type][$key])) {
            return $default;
        }

        $memory = $metadata['memory'][$type][$key];
        return $this->unserializeValue($memory['value']);
    }

    /**
     * Remove memory from a conversation.
     * 
     * @param Conversation $conversation The conversation
     * @param string $key The memory key
     * @param string $type Memory type
     * @return Conversation The updated conversation
     */
    public function removeMemory(
        Conversation $conversation,
        string $key,
        string $type = self::MEMORY_TYPE_CONVERSATION
    ): Conversation {
        $metadata = $conversation->getMetadata() ?? [];
        
        if (isset($metadata['memory'][$type][$key])) {
            unset($metadata['memory'][$type][$key]);
            
            // Clean up empty types
            if (empty($metadata['memory'][$type])) {
                unset($metadata['memory'][$type]);
            }
            
            // Clean up empty memory
            if (empty($metadata['memory'])) {
                unset($metadata['memory']);
            }

            $conversation->setMetadata($metadata);
            $this->entityManager->persist($conversation);
            $this->entityManager->flush();
        }

        return $conversation;
    }

    /**
     * Get all memory for a conversation.
     * 
     * @param Conversation $conversation The conversation
     * @param string|null $type Memory type (null for all)
     * @return array All memory
     */
    public function getAllMemory(
        Conversation $conversation,
        ?string $type = null
    ): array {
        $metadata = $conversation->getMetadata() ?? [];
        
        if (!isset($metadata['memory'])) {
            return [];
        }

        if ($type !== null) {
            return $metadata['memory'][$type] ?? [];
        }

        return $metadata['memory'];
    }

    /**
     * Clear all memory for a conversation.
     * 
     * @param Conversation $conversation The conversation
     * @param string|null $type Memory type (null for all)
     * @return Conversation The updated conversation
     */
    public function clearMemory(
        Conversation $conversation,
        ?string $type = null
    ): Conversation {
        $metadata = $conversation->getMetadata() ?? [];
        
        if ($type !== null) {
            if (isset($metadata['memory'][$type])) {
                unset($metadata['memory'][$type]);
            }
        } else {
            unset($metadata['memory']);
        }

        $conversation->setMetadata($metadata);
        $this->entityManager->persist($conversation);
        $this->entityManager->flush();

        return $conversation;
    }

    /**
     * Store conversation state (for continuation).
     * 
     * @param Conversation $conversation The conversation
     * @param array $state The state to store
     * @return Conversation The updated conversation
     */
    public function storeState(Conversation $conversation, array $state): Conversation
    {
        return $this->storeMemory(
            $conversation,
            'state',
            $state,
            self::MEMORY_TYPE_CONVERSATION
        );
    }

    /**
     * Retrieve conversation state.
     * 
     * @param Conversation $conversation The conversation
     * @return array The state or empty array
     */
    public function retrieveState(Conversation $conversation): array
    {
        return $this->retrieveMemory(
            $conversation,
            'state',
            self::MEMORY_TYPE_CONVERSATION,
            []
        );
    }

    /**
     * Store user preferences for a conversation.
     * 
     * @param Conversation $conversation The conversation
     * @param array $preferences The preferences
     * @return Conversation The updated conversation
     */
    public function storePreferences(Conversation $conversation, array $preferences): Conversation
    {
        return $this->storeMemory(
            $conversation,
            'preferences',
            $preferences,
            self::MEMORY_TYPE_CONVERSATION
        );
    }

    /**
     * Retrieve user preferences for a conversation.
     * 
     * @param Conversation $conversation The conversation
     * @return array The preferences or empty array
     */
    public function retrievePreferences(Conversation $conversation): array
    {
        return $this->retrieveMemory(
            $conversation,
            'preferences',
            self::MEMORY_TYPE_CONVERSATION,
            []
        );
    }

    /**
     * Store conversation tags.
     * 
     * @param Conversation $conversation The conversation
     * @param array $tags Array of tags
     * @return Conversation The updated conversation
     */
    public function storeTags(Conversation $conversation, array $tags): Conversation
    {
        return $this->storeMemory(
            $conversation,
            'tags',
            array_unique($tags),
            self::MEMORY_TYPE_CONVERSATION
        );
    }

    /**
     * Retrieve conversation tags.
     * 
     * @param Conversation $conversation The conversation
     * @return array Array of tags
     */
    public function retrieveTags(Conversation $conversation): array
    {
        return $this->retrieveMemory(
            $conversation,
            'tags',
            self::MEMORY_TYPE_CONVERSATION,
            []
        );
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
        $tags = $this->retrieveTags($conversation);
        $tags[] = $tag;
        return $this->storeTags($conversation, array_unique($tags));
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
        $tags = $this->retrieveTags($conversation);
        $tags = array_filter($tags, function($t) use ($tag) {
            return $t !== $tag;
        });
        return $this->storeTags($conversation, array_values($tags));
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
        $tags = $this->retrieveTags($conversation);
        return in_array($tag, $tags, true);
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
        $conversations = $user->getConversations();
        
        $filtered = [];
        foreach ($conversations as $conversation) {
            if ($this->hasTag($conversation, $tag)) {
                $filtered[] = $conversation;
            }
        }

        return $filtered;
    }

    /**
     * Store key-value pairs in memory.
     * 
     * @param Conversation $conversation The conversation
     * @param array $keyValues Array of key-value pairs
     * @param string $type Memory type
     * @return Conversation The updated conversation
     */
    public function storeKeyValues(
        Conversation $conversation,
        array $keyValues,
        string $type = self::MEMORY_TYPE_CONVERSATION
    ): Conversation {
        foreach ($keyValues as $key => $value) {
            $this->storeMemory($conversation, $key, $value, $type);
        }

        return $conversation;
    }

    /**
     * Get memory statistics for a conversation.
     * 
     * @param Conversation $conversation The conversation
     * @return array Memory statistics
     */
    public function getMemoryStatistics(Conversation $conversation): array
    {
        $memory = $this->getAllMemory($conversation);
        
        $stats = [
            'conversationId' => $conversation->getId(),
            'totalKeys' => 0,
            'byType' => [],
        ];

        foreach ($memory as $type => $typeMemory) {
            $count = count($typeMemory);
            $stats['totalKeys'] += $count;
            $stats['byType'][$type] = $count;
        }

        return $stats;
    }

    /**
     * Search conversations by memory content.
     * 
     * @param User $user The user
     * @param string $query The search query
     * @param string|null $type Memory type
     * @return Conversation[]
     */
    public function searchByMemory(User $user, string $query, ?string $type = null): array
    {
        $conversations = $user->getConversations();
        $results = [];

        foreach ($conversations as $conversation) {
            $memory = $this->getAllMemory($conversation, $type);
            
            foreach ($memory as $memType => $typeMemory) {
                if ($type !== null && $memType !== $type) {
                    continue;
                }

                foreach ($typeMemory as $key => $value) {
                    $value = $this->unserializeValue($value['value']);
                    
                    if ($this->containsQuery($value, $query)) {
                        $results[] = $conversation;
                        break 2;
                    }
                }
            }
        }

        return $results;
    }

    /**
     * Check if a value contains the query string.
     * 
     * @param mixed $value The value to check
     * @param string $query The query string
     * @return bool
     */
    private function containsQuery(mixed $value, string $query): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if ($this->containsQuery($item, $query)) {
                    return true;
                }
            }
            return false;
        }

        if (is_object($value)) {
            return false; // Don't search objects for now
        }

        return str_contains(strtolower((string)$value), strtolower($query));
    }

    /**
     * Serialize a value for storage.
     * 
     * @param mixed $value The value to serialize
     * @return string Serialized value
     */
    private function serializeValue(mixed $value): string
    {
        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_THROW_ON_ERROR);
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_null($value)) {
            return 'null';
        }

        return (string)$value;
    }

    /**
     * Unserialize a value from storage.
     * 
     * @param string $value The serialized value
     * @return mixed Unserialized value
     */
    private function unserializeValue(string $value): mixed
    {
        // Check for JSON
        if (preg_match('/^[{\[].*[}\]]$/', $value)) {
            try {
                return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            } catch (\Exception $e) {
                // Not JSON, continue
            }
        }

        // Check for boolean
        if ($value === 'true') {
            return true;
        }

        if ($value === 'false') {
            return false;
        }

        // Check for null
        if ($value === 'null') {
            return null;
        }

        // Check for numeric
        if (is_numeric($value)) {
            return str_contains($value, '.') ? (float)$value : (int)$value;
        }

        return $value;
    }

    /**
     * Get memory types.
     * 
     * @return array
     */
    public function getMemoryTypes(): array
    {
        return [
            self::MEMORY_TYPE_SHORT_TERM,
            self::MEMORY_TYPE_LONG_TERM,
            self::MEMORY_TYPE_CONVERSATION,
            self::MEMORY_TYPE_USER,
            self::MEMORY_TYPE_ORGANIZATION,
        ];
    }
}
