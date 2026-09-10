<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Onboarding;

use App\AI\Onboarding\ContextStoreManager;
use App\AI\Rag\Retriever;
use App\AI\Rag\RetrievalResult;
use App\AI\Rag\RetrievedItem;
use App\AI\Rag\VectorStore;
use App\Entity\Embedding;
use App\Entity\UserProfile;
use App\Repository\UserProfileRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Vollstaendige Test-Abdeckung fuer ContextStoreManager.
 *
 * Verifiziert load/saveContext, store*-Methoden, getRelevant* Abfragen
 * mit Filterung, createSystemPromptWithContext und delete*-Methoden.
 */
final class ContextStoreManagerTest extends TestCase
{
    private function createManager(
        ?VectorStore $vectorStore = null,
        ?Retriever $retriever = null,
        ?UserProfileRepository $repo = null
    ): ContextStoreManager {
        return new ContextStoreManager(
            $vectorStore ?? $this->createMock(VectorStore::class),
            $retriever ?? $this->createMock(Retriever::class),
            new NullLogger(),
            $repo
        );
    }

    private function createEmbedding(string $content, string $source, array $metadata): Embedding
    {
        $emb = new Embedding();
        $emb->setContent($content);
        $emb->setSource($source);
        $emb->setMetadata($metadata);
        return $emb;
    }

    private function createItem(string $content, string $source, array $metadata): RetrievedItem
    {
        return new RetrievedItem($this->createEmbedding($content, $source, $metadata), 0.9, 'user_profile');
    }

    private function createUserProfile(?int $id = 1, string $userType = 'recruiter'): UserProfile
    {
        $up = new UserProfile();
        if ($id !== null) {
            $ref = new \ReflectionProperty(UserProfile::class, 'id');
            $ref->setAccessible(true);
            $ref->setValue($up, $id);
        }
        $up->setUserIdentifier('user-123');
        $up->setUserType($userType);
        return $up;
    }

    public function testLoadContextWithoutRepositoryReturnsEmpty(): void
    {
        $manager = $this->createManager();
        self::assertSame([], $manager->loadContext('user-x'));
    }

    public function testLoadContextWithNoProfileReturnsEmpty(): void
    {
        $repo = $this->createMock(UserProfileRepository::class);
        $repo->method('findOneBy')->willReturn(null);
        $manager = $this->createManager(null, null, $repo);
        self::assertSame([], $manager->loadContext('missing'));
    }

    public function testLoadContextReturnsOnboardingData(): void
    {
        $profile = $this->createUserProfile();
        $profile->setOnboardingData(['step' => 1, 'name' => 'Test']);
        $repo = $this->createMock(UserProfileRepository::class);
        $repo->method('findOneBy')->willReturn($profile);
        $manager = $this->createManager(null, null, $repo);
        self::assertSame(['step' => 1, 'name' => 'Test'], $manager->loadContext('user-123'));
    }

    public function testLoadContextReturnsEmptyWhenOnboardingDataNotArray(): void
    {
        $profile = $this->createUserProfile();
        $repo = $this->createMock(UserProfileRepository::class);
        $repo->method('findOneBy')->willReturn($profile);
        $manager = $this->createManager(null, null, $repo);
        self::assertSame([], $manager->loadContext('user-123'));
    }

    public function testSaveContextWithoutRepositoryDoesNothing(): void
    {
        $manager = $this->createManager();
        $manager->saveContext('user-x', ['data' => 1]);
        $this->addToAssertionCount(1);
    }

    public function testSaveContextCreatesNewProfileIfNotFound(): void
    {
        $repo = $this->createMock(UserProfileRepository::class);
        $repo->expects(self::once())->method('findOneBy')->willReturn(null);
        $repo->expects(self::once())->method('save');
        $manager = $this->createManager(null, null, $repo);
        $manager->saveContext('new-user', ['step' => 1]);
    }

    public function testSaveContextUpdatesExistingProfile(): void
    {
        $profile = $this->createUserProfile();
        $profile->setOnboardingData(['old' => true]);
        $repo = $this->createMock(UserProfileRepository::class);
        $repo->method('findOneBy')->willReturn($profile);
        $repo->expects(self::once())->method('save');
        $manager = $this->createManager(null, null, $repo);
        $manager->saveContext('user-123', ['new' => true]);
        self::assertSame(['new' => true], $profile->getOnboardingData());
    }

    public function testStoreUserContextCallsVectorStore(): void
    {
        $vectorStore = $this->createMock(VectorStore::class);
        $embedding = $this->createMock(Embedding::class);
        $vectorStore->expects(self::once())
            ->method('store')
            ->with('ctx', 'user_profile', 'user_1', ['user_id' => 1, 'user_type' => 'recruiter'])
            ->willReturn($embedding);

        $manager = $this->createManager($vectorStore);
        $result = $manager->storeUserContext($this->createUserProfile(), 'ctx');
        self::assertSame($embedding, $result);
    }

    public function testStoreUserContextWithMetadata(): void
    {
        $vectorStore = $this->createMock(VectorStore::class);
        $embedding = new Embedding();
        $vectorStore->method('store')->willReturn($embedding);

        $manager = $this->createManager($vectorStore);
        $manager->storeUserContext($this->createUserProfile(), 'ctx', ['extra' => 'val']);
        $this->addToAssertionCount(1);
    }

    public function testStoreConversationMemoryCallsVectorStore(): void
    {
        $vectorStore = $this->createMock(VectorStore::class);
        $embedding = new Embedding();
        $vectorStore->expects(self::once())
            ->method('store')
            ->with('conv', 'conversation', 'session_s1', ['session_id' => 's1'])
            ->willReturn($embedding);

        $manager = $this->createManager($vectorStore);
        $result = $manager->storeConversationMemory('s1', 'conv');
        self::assertSame($embedding, $result);
    }

    public function testStoreToolMemoryCallsVectorStore(): void
    {
        $vectorStore = $this->createMock(VectorStore::class);
        $embedding = new Embedding();
        $vectorStore->expects(self::once())
            ->method('store')
            ->with('tool content', 'tool_memory', 'tool_search', ['tool_name' => 'search'])
            ->willReturn($embedding);

        $manager = $this->createManager($vectorStore);
        $result = $manager->storeToolMemory('search', 'tool content');
        self::assertSame($embedding, $result);
    }

    public function testStoreKnowledgeCallsVectorStore(): void
    {
        $vectorStore = $this->createMock(VectorStore::class);
        $embedding = new Embedding();
        $vectorStore->expects(self::once())
            ->method('store')
            ->with('knowledge', 'knowledge', 'wiki', ['author' => 'me'])
            ->willReturn($embedding);

        $manager = $this->createManager($vectorStore);
        $result = $manager->storeKnowledge('knowledge', 'wiki', ['author' => 'me']);
        self::assertSame($embedding, $result);
    }

    public function testGetRelevantUserContextFiltersByUserId(): void
    {
        $item1 = $this->createItem('ctx1', 'src1', ['user_id' => 1]);
        $item2 = $this->createItem('ctx2', 'src2', ['user_id' => 2]);
        $item3 = $this->createItem('ctx3', 'src3', ['user_id' => 1]);

        $retriever = $this->createMock(Retriever::class);
        $retriever->method('retrieveForType')
            ->willReturn(new RetrievalResult('q', [$item1, $item2, $item3]));

        $manager = $this->createManager(null, $retriever);
        $result = $manager->getRelevantUserContext($this->createUserProfile(1), 'query');
        self::assertCount(2, $result);
    }

    public function testGetRelevantUserContextWithNoMatchesReturnsEmpty(): void
    {
        $item = $this->createItem('ctx', 'src', ['user_id' => 999]);
        $retriever = $this->createMock(Retriever::class);
        $retriever->method('retrieveForType')->willReturn(new RetrievalResult('q', [$item]));

        $manager = $this->createManager(null, $retriever);
        $result = $manager->getRelevantUserContext($this->createUserProfile(1), 'query');
        self::assertSame([], $result);
    }

    public function testGetRelevantConversationContextFiltersBySessionId(): void
    {
        $item1 = $this->createItem('c1', 's1', ['session_id' => 'sess1']);
        $item2 = $this->createItem('c2', 's2', ['session_id' => 'sess2']);
        $retriever = $this->createMock(Retriever::class);
        $retriever->method('retrieveForType')->willReturn(new RetrievalResult('q', [$item1, $item2]));

        $manager = $this->createManager(null, $retriever);
        $result = $manager->getRelevantConversationContext('sess1', 'query');
        self::assertCount(1, $result);
    }

    public function testGetRelevantToolContextFiltersByToolName(): void
    {
        $item1 = $this->createItem('t1', 'tool_a', ['tool_name' => 'toolA']);
        $item2 = $this->createItem('t2', 'tool_b', ['tool_name' => 'toolB']);
        $retriever = $this->createMock(Retriever::class);
        $retriever->method('retrieveForType')->willReturn(new RetrievalResult('q', [$item1, $item2]));

        $manager = $this->createManager(null, $retriever);
        $result = $manager->getRelevantToolContext('toolA', 'query');
        self::assertCount(1, $result);
    }

    public function testGetRelevantKnowledgeReturnsAllItems(): void
    {
        $item1 = $this->createItem('k1', 'wiki', []);
        $item2 = $this->createItem('k2', 'doc', []);
        $retriever = $this->createMock(Retriever::class);
        $retriever->method('retrieveForType')->willReturn(new RetrievalResult('q', [$item1, $item2]));

        $manager = $this->createManager(null, $retriever);
        $result = $manager->getRelevantKnowledge('query');
        self::assertCount(2, $result);
    }

    public function testGetRelevantContextWithMissingMetadataFiltersOut(): void
    {
        $item = $this->createItem('c', 's', []);
        $retriever = $this->createMock(Retriever::class);
        $retriever->method('retrieveForType')->willReturn(new RetrievalResult('q', [$item]));

        $manager = $this->createManager(null, $retriever);
        $result = $manager->getRelevantUserContext($this->createUserProfile(1), 'query');
        self::assertSame([], $result);
    }

    public function testCreateSystemPromptWithContextEmptyReturnsBasePrompt(): void
    {
        $retriever = $this->createMock(Retriever::class);
        $retriever->method('retrieveForType')->willReturn(new RetrievalResult('q', []));

        $manager = $this->createManager(null, $retriever);
        $prompt = $manager->createSystemPromptWithContext($this->createUserProfile(), 'query');
        self::assertSame('Du bist ein hilfreicher AI-Assistent.', $prompt);
    }

    public function testCreateSystemPromptWithContextReturnsContextString(): void
    {
        $userItem = $this->createItem('user context', 'user_src', ['user_id' => 1]);
        $knowledgeItem = $this->createItem('knowledge data', 'wiki_src', []);

        $retriever = $this->createMock(Retriever::class);
        $retriever->method('retrieveForType')
            ->willReturnOnConsecutiveCalls(
                new RetrievalResult('q', [$userItem]),
                new RetrievalResult('q', [$knowledgeItem])
            );

        $manager = $this->createManager(null, $retriever);
        $prompt = $manager->createSystemPromptWithContext($this->createUserProfile(1), 'query');
        self::assertStringContainsString('## Relevanter Kontext:', $prompt);
        self::assertStringContainsString('[User Context - user_src]', $prompt);
        self::assertStringContainsString('[Knowledge - wiki_src]', $prompt);
        self::assertStringContainsString('user context', $prompt);
        self::assertStringContainsString('knowledge data', $prompt);
    }

    public function testDeleteUserContextCallsVectorStoreDelete(): void
    {
        $vectorStore = $this->createMock(VectorStore::class);
        $vectorStore->expects(self::once())
            ->method('delete')
            ->with('user_profile', 'user_1')
            ->willReturn(5);

        $manager = $this->createManager($vectorStore);
        $result = $manager->deleteUserContext($this->createUserProfile(1));
        self::assertSame(5, $result);
    }

    public function testDeleteSessionContextCallsVectorStoreDelete(): void
    {
        $vectorStore = $this->createMock(VectorStore::class);
        $vectorStore->expects(self::once())
            ->method('delete')
            ->with('conversation', 'session_sess42')
            ->willReturn(3);

        $manager = $this->createManager($vectorStore);
        $result = $manager->deleteSessionContext('sess42');
        self::assertSame(3, $result);
    }

    public function testGetRelevantContextCustomLimitPassedToRetriever(): void
    {
        $retriever = $this->createMock(Retriever::class);
        $retriever->expects(self::once())
            ->method('retrieveForType')
            ->with('query', 'user_profile', 10)
            ->willReturn(new RetrievalResult('q', []));

        $manager = $this->createManager(null, $retriever);
        $manager->getRelevantUserContext($this->createUserProfile(1), 'query', 10);
    }

    public function testStoreToolMemoryWithMetadata(): void
    {
        $vectorStore = $this->createMock(VectorStore::class);
        $vectorStore->method('store')->willReturn(new Embedding());

        $manager = $this->createManager($vectorStore);
        $manager->storeToolMemory('search', 'content', ['version' => 2]);
        $this->addToAssertionCount(1);
    }

    public function testStoreKnowledgeWithoutMetadata(): void
    {
        $vectorStore = $this->createMock(VectorStore::class);
        $vectorStore->expects(self::once())
            ->method('store')
            ->with('content', 'knowledge', 'src', [])
            ->willReturn(new Embedding());

        $manager = $this->createManager($vectorStore);
        $manager->storeKnowledge('content', 'src');
    }
}
