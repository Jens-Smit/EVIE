<?php

namespace App\AI\Onboarding;

use App\Entity\UserProfile;
use App\Entity\Embedding;
use App\AI\Rag\VectorStore;
use App\AI\Rag\Retriever;
use Psr\Log\LoggerInterface;

class ContextStoreManager
{
    public function __construct(
        private VectorStore $vectorStore,
        private Retriever $retriever,
        private LoggerInterface $logger
    ) {
    }

    public function storeUserContext(UserProfile $userProfile, string $context, array $metadata = []): Embedding
    {
        return $this->vectorStore->store(
            $context,
            'user_profile',
            'user_' . $userProfile->getId(),
            array_merge($metadata, [
                'user_id' => $userProfile->getId(),
                'user_type' => $userProfile->getUserType(),
            ])
        );
    }

    public function storeConversationMemory(string $sessionId, string $content, array $metadata = []): Embedding
    {
        return $this->vectorStore->store(
            $content,
            'conversation',
            'session_' . $sessionId,
            array_merge($metadata, ['session_id' => $sessionId])
        );
    }

    public function storeToolMemory(string $toolName, string $content, array $metadata = []): Embedding
    {
        return $this->vectorStore->store(
            $content,
            'tool_memory',
            'tool_' . $toolName,
            array_merge($metadata, ['tool_name' => $toolName])
        );
    }

    public function storeKnowledge(string $content, string $source, array $metadata = []): Embedding
    {
        return $this->vectorStore->store(
            $content,
            'knowledge',
            $source,
            $metadata
        );
    }

    public function getRelevantUserContext(UserProfile $userProfile, string $query, int $limit = 5): array
    {
        $result = $this->retriever->retrieveForType($query, 'user_profile', $limit);
        return array_filter($result->getItems(), function($item) use ($userProfile) {
            return ($item->getMetadata()['user_id'] ?? null) === $userProfile->getId();
        });
    }

    public function getRelevantConversationContext(string $sessionId, string $query, int $limit = 5): array
    {
        $result = $this->retriever->retrieveForType($query, 'conversation', $limit);
        return array_filter($result->getItems(), function($item) use ($sessionId) {
            return ($item->getMetadata()['session_id'] ?? null) === $sessionId;
        });
    }

    public function getRelevantToolContext(string $toolName, string $query, int $limit = 5): array
    {
        $result = $this->retriever->retrieveForType($query, 'tool_memory', $limit);
        return array_filter($result->getItems(), function($item) use ($toolName) {
            return ($item->getMetadata()['tool_name'] ?? null) === $toolName;
        });
    }

    public function getRelevantKnowledge(string $query, int $limit = 5): array
    {
        $result = $this->retriever->retrieveForType($query, 'knowledge', $limit);
        return $result->getItems();
    }

    public function createSystemPromptWithContext(UserProfile $userProfile, string $query, array $options = []): string
    {
        $basePrompt = $userProfile->getSystemPrompt() ?? 'Du bist ein hilfreicher AI-Assistent.';
        
        $userContext = $this->getRelevantUserContext($userProfile, $query);
        $knowledgeContext = $this->getRelevantKnowledge($query);
        
        $contexts = [];
        foreach ($userContext as $item) {
            $contexts[] = sprintf("[User Context - %s]
%s", $item->getSource(), $item->getContent());
        }
        
        foreach ($knowledgeContext as $item) {
            $contexts[] = sprintf("[Knowledge - %s]
%s", $item->getSource(), $item->getContent());
        }

        if (empty($contexts)) {
            return $basePrompt;
        }

        $contextString = implode("

---

", $contexts);
        return $basePrompt . "

## Relevanter Kontext:
" . $contextString . "

";
    }

    public function deleteUserContext(UserProfile $userProfile): int
    {
        return $this->vectorStore->delete('user_profile', 'user_' . $userProfile->getId());
    }

    public function deleteSessionContext(string $sessionId): int
    {
        return $this->vectorStore->delete('conversation', 'session_' . $sessionId);
    }
}
