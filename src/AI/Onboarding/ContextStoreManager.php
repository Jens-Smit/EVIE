<?php

namespace App\AI\Onboarding;

use App\Entity\UserProfile;
use App\Repository\UserProfileRepository;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

#[Autoconfigure(tags: ['ai.context_manager'])]
class ContextStoreManager
{
    private UserProfileRepository $userProfileRepo;

    public function __construct(UserProfileRepository $userProfileRepo)
    {
        $this->userProfileRepo = $userProfileRepo;
    }

    /**
     * Loads the user context from the store.
     */
    public function loadContext(string $userIdentifier): array
    {
        $userProfile = $this->userProfileRepo->findOneBy(['userIdentifier' => $userIdentifier]);

        if (!$userProfile) {
            return [
                'user_type' => 'unknown',
                'preferences' => [],
                'context_embedding' => null,
            ];
        }

        return [
            'user_type' => $userProfile->getUserType(),
            'preferences' => $userProfile->getPreferences(),
            'context_embedding' => $userProfile->getContextEmbedding(),
        ];
    }

    /**
     * Saves the user context to the store.
     */
    public function saveContext(string $userIdentifier, array $context): void
    {
        $userProfile = $this->userProfileRepo->findOneBy(['userIdentifier' => $userIdentifier]);

        if (!$userProfile) {
            $userProfile = new UserProfile();
            $userProfile->setUserIdentifier($userIdentifier);
        }

        if (isset($context['user_type'])) {
            $userProfile->setUserType($context['user_type']);
        }

        if (isset($context['preferences'])) {
            $userProfile->setPreferences($context['preferences']);
        }

        if (isset($context['context_embedding'])) {
            $userProfile->setContextEmbedding($context['context_embedding']);
        }

        $userProfile->setUpdatedAt(new \DateTimeImmutable());
        $this->userProfileRepo->save($userProfile, true);
    }

    /**
     * Updates the user's context embedding.
     */
    public function updateContextEmbedding(string $userIdentifier, string $embedding): void
    {
        $userProfile = $this->userProfileRepo->findOneBy(['userIdentifier' => $userIdentifier]);

        if (!$userProfile) {
            $userProfile = new UserProfile();
            $userProfile->setUserIdentifier($userIdentifier);
        }

        $userProfile->setContextEmbedding($embedding);
        $userProfile->setUpdatedAt(new \DateTimeImmutable());
        $this->userProfileRepo->save($userProfile, true);
    }

    /**
     * Retrieves the user's context as a system prompt for the LLM.
     */
    public function getSystemPrompt(string $userIdentifier): string
    {
        $context = $this->loadContext($userIdentifier);

        $promptParts = [];
        $promptParts[] = sprintf("User Type: %s", $context['user_type'] ?? 'unknown');

        if (!empty($context['preferences'])) {
            $promptParts[] = "Preferences: " . json_encode($context['preferences']);
        }

        if ($context['context_embedding']) {
            $promptParts[] = "Context Embedding: [Vector data - omitted for readability]";
        }

        return implode("\n", $promptParts);
    }
}
