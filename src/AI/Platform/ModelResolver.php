<?php

namespace App\AI\Platform;

use App\Entity\UserProfile;
use App\Repository\UserProfileRepository;

/**
 * Resolves the appropriate model string based on tenant preferences and agent role.
 * 
 * This service allows each tenant to use their preferred LLM model
 * for their agents, with the ability to specify different models for different roles.
 */
class ModelResolver
{
    private const DEFAULT_MODELS = [
        'orchestrator' => 'mistral-small-latest',
        'tool_generator' => 'mistral-small-latest',
        'onboarding' => 'mistral-small-latest',
        'sub_agent' => 'mistral-small-latest',
    ];

    public function __construct(
        private readonly UserProfileRepository $userProfileRepository,
        private readonly PlatformResolver $platformResolver,
    ) {
    }

    /**
     * Resolve the model for a given user identifier and agent role.
     * 
     * @param string $userIdentifier The tenant identifier
     * @param string $agentRole The agent role (e.g., 'orchestrator', 'tool_generator')
     * @return string The resolved model string
     */
    public function resolveModel(string $userIdentifier, string $agentRole = 'orchestrator'): string
    {
        $profile = $this->userProfileRepository->findOneBy(['userIdentifier' => $userIdentifier]);
        
        if (null === $profile) {
            return $this->getDefaultModelForRole($agentRole);
        }

        // Check if user has a preferred model
        $preferredModel = $profile->getPreferredLlmModel();
        
        if (null !== $preferredModel && !empty($preferredModel)) {
            return $preferredModel;
        }

        // Fall back to provider default
        $provider = $profile->getPreferredLlmProvider();
        
        if (null !== $provider) {
            return $this->platformResolver->getDefaultModel($provider);
        }

        return $this->getDefaultModelForRole($agentRole);
    }

    /**
     * Resolve the model for a UserProfile entity and agent role.
     * 
     * @param UserProfile $profile The user profile
     * @param string $agentRole The agent role
     * @return string The resolved model string
     */
    public function resolveModelForProfile(UserProfile $profile, string $agentRole = 'orchestrator'): string
    {
        // Check if user has a preferred model
        $preferredModel = $profile->getPreferredLlmModel();
        
        if (null !== $preferredModel && !empty($preferredModel)) {
            return $preferredModel;
        }

        // Fall back to provider default
        $provider = $profile->getPreferredLlmProvider();
        
        if (null !== $provider) {
            return $this->platformResolver->getDefaultModel($provider);
        }

        return $this->getDefaultModelForRole($agentRole);
    }

    /**
     * Get the default model for a specific agent role.
     * 
     * @param string $agentRole The agent role
     * @return string The default model
     */
    public function getDefaultModelForRole(string $agentRole): string
    {
        return self::DEFAULT_MODELS[$agentRole] ?? self::DEFAULT_MODELS['orchestrator'];
    }

    /**
     * Set the preferred model for a user.
     * 
     * @param string $userIdentifier The tenant identifier
     * @param string $model The model to set
     */
    public function setPreferredModel(string $userIdentifier, string $model): void
    {
        $profile = $this->userProfileRepository->findOneBy(['userIdentifier' => $userIdentifier]);
        
        if (null === $profile) {
            throw new \InvalidArgumentException(sprintf('No profile found for user identifier: %s', $userIdentifier));
        }

        $profile->setPreferredLlmModel($model);
        $profile->setUpdatedAt(new \DateTimeImmutable());
        
        $this->userProfileRepository->save($profile, true);
    }

    /**
     * Set the preferred provider for a user.
     * 
     * @param string $userIdentifier The tenant identifier
     * @param string $provider The provider to set
     */
    public function setPreferredProvider(string $userIdentifier, string $provider): void
    {
        $profile = $this->userProfileRepository->findOneBy(['userIdentifier' => $userIdentifier]);
        
        if (null === $profile) {
            throw new \InvalidArgumentException(sprintf('No profile found for user identifier: %s', $userIdentifier));
        }

        $profile->setPreferredLlmProvider($provider);
        $profile->setUpdatedAt(new \DateTimeImmutable());
        
        $this->userProfileRepository->save($profile, true);
    }
}
