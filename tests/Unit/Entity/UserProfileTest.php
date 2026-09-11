<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\AgentHistory;
use App\Entity\Document;
use App\Entity\SubAgent;
use App\Entity\User;
use App\Entity\UserProfile;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class UserProfileTest extends TestCase
{
    public function testDefaults(): void
    {
        $profile = new UserProfile();
        $profile->setUserIdentifier('user-1');

        self::assertNull($profile->getId());
        self::assertNull($profile->getName());
        self::assertSame('user-1', $profile->getUserIdentifier());
        self::assertNull($profile->getEmail());
        self::assertSame([], $profile->getPreferences());
        self::assertSame('unknown', $profile->getUserType());
        self::assertNull($profile->getContextEmbedding());
        self::assertSame([], $profile->getOnboardingData());
        self::assertNull($profile->getUpdatedAt());
        self::assertNull($profile->getUser());
        self::assertSame([], $profile->getAgentHistories()->toArray());
        self::assertSame([], $profile->getDocuments()->toArray());
        self::assertSame([], $profile->getSubAgents()->toArray());
        self::assertNull($profile->getPreferredLlmProvider());
        self::assertNull($profile->getPreferredLlmModel());
    }

    public function testGettersAndSetters(): void
    {
        $profile = new UserProfile();
        $user = new User();
        $updatedAt = new DateTimeImmutable('2024-01-01 00:00:00');

        $profile
            ->setUserIdentifier('user-1')
            ->setName('Alice')
            ->setEmail('alice@example.com')
            ->setPreferences(['theme' => 'dark'])
            ->setUserType('developer')
            ->setContextEmbedding('vec-123')
            ->setOnboardingData(['step' => 2])
            ->setUpdatedAt($updatedAt)
            ->setUser($user);

        self::assertSame('user-1', $profile->getUserIdentifier());
        self::assertSame('Alice', $profile->getName());
        self::assertSame('alice@example.com', $profile->getEmail());
        self::assertSame(['theme' => 'dark'], $profile->getPreferences());
        self::assertSame('developer', $profile->getUserType());
        self::assertSame('vec-123', $profile->getContextEmbedding());
        self::assertSame(['step' => 2], $profile->getOnboardingData());
        self::assertSame($updatedAt, $profile->getUpdatedAt());
        self::assertSame($user, $profile->getUser());
    }

    public function testPreferredLlmProviderAndModel(): void
    {
        $profile = new UserProfile();
        $profile->setUserIdentifier('u');

        $profile->setPreferredLlmProvider('mistral');
        self::assertSame('mistral', $profile->getPreferredLlmProvider());

        $profile->setPreferredLlmModel('mistral-large');
        self::assertSame('mistral-large', $profile->getPreferredLlmModel());

        self::assertSame('mistral', $profile->getPreferences()['llm_provider']);
        self::assertSame('mistral-large', $profile->getPreferences()['llm_model']);

        $profile->setPreferredLlmProvider(null);
        self::assertNull($profile->getPreferredLlmProvider());
        $profile->setPreferredLlmModel(null);
        self::assertNull($profile->getPreferredLlmModel());
    }

    public function testPreferredLlmProviderPreservesExistingPreferences(): void
    {
        $profile = new UserProfile();
        $profile->setUserIdentifier('u');
        $profile->setPreferences(['theme' => 'dark']);

        $profile->setPreferredLlmProvider('gemini');
        self::assertSame('dark', $profile->getPreferences()['theme']);
        self::assertSame('gemini', $profile->getPreferences()['llm_provider']);
    }

    public function testAgentHistoryCollectionAddRemove(): void
    {
        $profile = new UserProfile();
        $profile->setUserIdentifier('u');
        $history = new AgentHistory();
        $history->setAction('tool_call');

        $profile->addAgentHistory($history);
        self::assertSame($profile, $history->getUser());
        self::assertCount(1, $profile->getAgentHistories());

        // Adding the same instance twice does not duplicate.
        $profile->addAgentHistory($history);
        self::assertCount(1, $profile->getAgentHistories());

        $profile->removeAgentHistory($history);
        self::assertCount(0, $profile->getAgentHistories());
        self::assertNull($history->getUser());
    }

    public function testRemoveAgentHistoryNotPresentIsNoop(): void
    {
        $profile = new UserProfile();
        $profile->setUserIdentifier('u');
        $history = new AgentHistory();
        $history->setAction('tool_call');

        $profile->removeAgentHistory($history);
        self::assertCount(0, $profile->getAgentHistories());
    }

    public function testDocumentCollectionAddRemove(): void
    {
        $profile = new UserProfile();
        $profile->setUserIdentifier('u');
        $document = new Document();

        $profile->addDocument($document);
        self::assertSame($profile, $document->getUser());
        self::assertCount(1, $profile->getDocuments());

        $profile->addDocument($document);
        self::assertCount(1, $profile->getDocuments());

        $profile->removeDocument($document);
        self::assertCount(0, $profile->getDocuments());
        self::assertNull($document->getUser());
    }

    public function testRemoveDocumentNotPresentIsNoop(): void
    {
        $profile = new UserProfile();
        $profile->setUserIdentifier('u');
        $document = new Document();

        $profile->removeDocument($document);
        self::assertCount(0, $profile->getDocuments());
    }

    public function testSubAgentCollectionAddRemove(): void
    {
        $profile = new UserProfile();
        $profile->setUserIdentifier('u');
        $subAgent = new SubAgent();

        $profile->addSubAgent($subAgent);
        self::assertSame($profile, $subAgent->getUser());
        self::assertCount(1, $profile->getSubAgents());

        $profile->addSubAgent($subAgent);
        self::assertCount(1, $profile->getSubAgents());

        $profile->removeSubAgent($subAgent);
        self::assertCount(0, $profile->getSubAgents());
        self::assertNull($subAgent->getUser());
    }

    public function testRemoveSubAgentNotPresentIsNoop(): void
    {
        $profile = new UserProfile();
        $profile->setUserIdentifier('u');
        $subAgent = new SubAgent();

        $profile->removeSubAgent($subAgent);
        self::assertCount(0, $profile->getSubAgents());
    }
}
