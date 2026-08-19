<?php

namespace App\AI\LLM;

use App\Entity\User;
use App\AI\LLM\LLMProviderFactory;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Resolver für LLM-Plattformen
 * 
 * Löst die passende Platform für einen User basierend auf seiner Konfiguration auf.
 * Falls der User einen eigenen API-Key hinterlegt hat, wird dieser verwendet.
 * Sonst wird der Default-Key aus der Environment-Konfiguration verwendet.
 */
class LLMPlatformResolver
{
    public function __construct(
        private LLMProviderFactory $providerFactory,
        private string $mistralApiKey,
        private string $openAiApiKey,
        private string $googleApiKey,
        private string $anthropicApiKey
    ) {
    }

    /**
     * Löst die passende Platform für einen User auf
     * 
     * @param User $user Der User, für den die Platform ermittelt werden soll
     * @return PlatformInterface Die Platform für den User
     */
    public function resolveForUser(User $user): PlatformInterface
    {
        $provider = $this->providerFactory->getUserProvider($user);
        $userApiKey = $this->providerFactory->getUserApiKey($user);

        // Falls User einen eigenen API-Key hat, diesen verwenden
        if ($userApiKey) {
            return $provider->createPlatform($userApiKey);
        }

        // Sonst den Default-Key aus .env verwenden
        $envKey = $this->getEnvKeyForProvider($provider->getName());
        
        return $provider->createPlatform($envKey);
    }

    /**
     * Gibt das Modell für einen User zurück
     * 
     * @param User $user Der User, für den das Modell ermittelt werden soll
     * @return string Das Modell für den User
     */
    public function getModelForUser(User $user): string
    {
        return $this->providerFactory->getUserModel($user);
    }

    /**
     * Gibt die API-URL für einen User zurück
     * 
     * @param User $user Der User, für den die API-URL ermittelt werden soll
     * @return string Die API-URL
     */
    public function getApiUrlForUser(User $user): string
    {
        return $this->providerFactory->getUserApiUrl($user);
    }

    /**
     * Gibt den Provider-Namen für einen User zurück
     * 
     * @param User $user Der User, für den der Provider ermittelt werden soll
     * @return string Der Provider-Name
     */
    public function getProviderNameForUser(User $user): string
    {
        $provider = $this->providerFactory->getUserProvider($user);
        return $provider->getName();
    }

    /**
     * Gibt den Environment-Key für einen Provider zurück
     * 
     * @param string $provider Der Provider-Name
     * @return string Der API-Key aus der Environment
     * @throws \RuntimeException Falls kein Key für den Provider konfiguriert ist
     */
    private function getEnvKeyForProvider(string $provider): string
    {
        return match ($provider) {
            'mistral' => $this->mistralApiKey,
            'openai' => $this->openAiApiKey,
            'google' => $this->googleApiKey,
            'anthropic' => $this->anthropicApiKey,
            default => throw new \RuntimeException("No default API key configured for provider: $provider")
        };
    }

    /**
     * Erstellt eine Platform mit einem spezifischen API-Key
     * 
     * @param string $provider Der Provider-Name
     * @param string $apiKey Der API-Key
     * @return PlatformInterface Die Platform
     */
    public function createPlatformWithKey(string $provider, string $apiKey): PlatformInterface
    {
        $llmProvider = $this->providerFactory->getProvider($provider);
        return $llmProvider->createPlatform($apiKey);
    }

    /**
     * Prüft, ob ein User eine benutzerdefinierte Konfiguration hat
     * 
     * @param User $user Der User
     * @return bool True, falls der User eine benutzerdefinierte Konfiguration hat
     */
    public function hasCustomConfiguration(User $user): bool
    {
        $config = $this->providerFactory->getUserApiKey($user);
        return $config !== null;
    }
}
