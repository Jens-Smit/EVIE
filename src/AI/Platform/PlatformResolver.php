<?php

namespace App\AI\Platform;

use App\Entity\UserProfile;
use App\Repository\UserProfileRepository;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Resolves the appropriate PlatformInterface based on tenant preferences.
 * 
 * This service allows each tenant to use their preferred LLM provider
 * (e.g., Mistral, Gemini) for their agents.
 */
class PlatformResolver
{
    public function __construct(
        private readonly UserProfileRepository $userProfileRepository,
        #[Autowire(service: 'ai.platform.mistral')]
        private readonly PlatformInterface $mistralPlatform,
        #[Autowire(service: 'ai.platform.gemini')]
        private readonly PlatformInterface $geminiPlatform,
    ) {
    }

    /**
     * Resolve the platform for a given user identifier.
     * 
     * @param string $userIdentifier The tenant identifier
     * @return PlatformInterface The resolved platform
     */
    public function resolvePlatform(string $userIdentifier): PlatformInterface
    {
        $profile = $this->userProfileRepository->findOneBy(['userIdentifier' => $userIdentifier]);
        
        if (null === $profile) {
            // Default to Mistral if no profile exists
            return $this->mistralPlatform;
        }

        $provider = $profile->getPreferredLlmProvider();
        
        return match ($provider) {
            'gemini' => $this->geminiPlatform,
            'mistral' => $this->mistralPlatform,
            default => $this->mistralPlatform,
        };
    }

    /**
     * Resolve the platform for a UserProfile entity.
     * 
     * @param UserProfile $profile The user profile
     * @return PlatformInterface The resolved platform
     */
    public function resolvePlatformForProfile(UserProfile $profile): PlatformInterface
    {
        $provider = $profile->getPreferredLlmProvider();
        
        return match ($provider) {
            'gemini' => $this->geminiPlatform,
            'mistral' => $this->mistralPlatform,
            default => $this->mistralPlatform,
        };
    }

    /**
     * Get the available platform choices.
     * 
     * @return array<string, string> Array of provider name to display name
     */
    public function getAvailableProviders(): array
    {
        return [
            'mistral' => 'Mistral AI',
            'gemini' => 'Google Gemini',
        ];
    }

    /**
     * Get the available models for each provider.
     * 
     * @return array<string, array<string>> Array of provider to models
     */
    public function getAvailableModels(): array
    {
        return [
            'mistral' => [
                'mistral-large-latest' => 'Mistral Large (Latest)',
                'mistral-small-latest' => 'Mistral Small (Latest)',
                'mistral-tiny-latest' => 'Mistral Tiny (Latest)',
                'open-mistral-7b' => 'Open Mistral 7B',
                'open-mixtral-8x7b' => 'Open Mixtral 8x7B',
                'open-mixtral-8x22b' => 'Open Mixtral 8x22B',
            ],
            'gemini' => [
                'gemini-1.5-pro-latest' => 'Gemini 1.5 Pro (Latest)',
                'gemini-1.5-flash-latest' => 'Gemini 1.5 Flash (Latest)',
                'gemini-1.0-pro-latest' => 'Gemini 1.0 Pro (Latest)',
                'gemini-1.0-ultra-latest' => 'Gemini 1.0 Ultra (Latest)',
            ],
        ];
    }

    /**
     * Get the default model for a provider.
     * 
     * @param string $provider The provider name
     * @return string The default model
     */
    public function getDefaultModel(string $provider): string
    {
        $models = $this->getAvailableModels();
        
        if (isset($models[$provider]) && !empty($models[$provider])) {
            return array_key_first($models[$provider]);
        }
        
        return 'mistral-small-latest';
    }
}
