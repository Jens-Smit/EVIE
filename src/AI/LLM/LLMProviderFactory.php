<?php

namespace App\AI\LLM;

use App\Entity\LLMConfiguration;
use App\Entity\User;
use App\Repository\LLMConfigurationRepository;
use App\AI\LLM\Provider\MistralProvider;
use App\AI\LLM\Provider\OpenAIProvider;
use App\AI\LLM\Provider\GoogleProvider;
use App\AI\LLM\Provider\AnthropicProvider;
use App\AI\LLM\Provider\CustomProvider;

/**
 * Factory für LLM-Provider
 * 
 * Verwaltet alle verfügbaren Provider und stellt Methoden bereit,
 * um den passenden Provider für einen User zu ermitteln.
 */
class LLMProviderFactory
{
    /**
     * @var array Assoziatives Array aller verfügbaren Provider
     */
    private array $providers = [];

    public function __construct(
        private LLMConfigurationRepository $configRepo,
        private string $defaultProvider = 'mistral',
        private string $defaultModel = 'mistral-small-latest'
    ) {
        $this->providers = [
            'mistral' => new MistralProvider(),
            'openai' => new OpenAIProvider(),
            'google' => new GoogleProvider(),
            'anthropic' => new AnthropicProvider(),
            'custom' => new CustomProvider(),
        ];
    }

    /**
     * Gibt den Provider für einen User zurück
     * 
     * @param User $user Der User, für den der Provider ermittelt werden soll
     * @return LLMProviderInterface Der Provider für den User
     */
    public function getUserProvider(User $user): LLMProviderInterface
    {
        $config = $this->configRepo->findDefaultForUser($user);
        
        if ($config) {
            return $this->getProvider($config->getProvider());
        }
        
        return $this->getProvider($this->defaultProvider);
    }

    /**
     * Gibt das Modell für einen User zurück
     * 
     * @param User $user Der User, für den das Modell ermittelt werden soll
     * @return string Das Modell für den User
     */
    public function getUserModel(User $user): string
    {
        $config = $this->configRepo->findDefaultForUser($user);
        
        if ($config) {
            return $config->getModel();
        }
        
        return $this->defaultModel;
    }

    /**
     * Gibt den API-Key für einen User zurück (falls hinterlegt)
     * 
     * @param User $user Der User, für den der API-Key ermittelt werden soll
     * @return string|null Der API-Key oder null, falls keiner hinterlegt ist
     */
    public function getUserApiKey(User $user): ?string
    {
        $config = $this->configRepo->findDefaultForUser($user);
        
        if ($config && $config->getApiKey()) {
            return $config->getApiKey();
        }
        
        return null;
    }

    /**
     * Gibt die API-URL für einen User zurück
     * 
     * @param User $user Der User, für den die API-URL ermittelt werden soll
     * @return string Die API-URL
     */
    public function getUserApiUrl(User $user): string
    {
        $config = $this->configRepo->findDefaultForUser($user);
        
        if ($config && $config->isCustom() && $config->getCustomApiUrl()) {
            return $config->getCustomApiUrl();
        }
        
        $provider = $this->getUserProvider($user);
        return $provider->getApiUrl();
    }

    /**
     * Gibt einen spezifischen Provider zurück
     * 
     * @param string $name Der Name des Providers
     * @return LLMProviderInterface Der Provider
     * @throws \InvalidArgumentException Falls der Provider nicht existiert
     */
    public function getProvider(string $name): LLMProviderInterface
    {
        if (!isset($this->providers[$name])) {
            throw new \InvalidArgumentException("Unknown LLM provider: $name");
        }
        
        return $this->providers[$name];
    }

    /**
     * Gibt alle verfügbaren Provider zurück
     * 
     * @return array Array von Provider-Namen
     */
    public function getAvailableProviders(): array
    {
        return array_keys($this->providers);
    }

    /**
     * Gibt alle verfügbaren Modelle für einen Provider zurück
     * 
     * @param string $provider Der Name des Providers
     * @return array Array von Modell-Namen
     */
    public function getAvailableModels(string $provider): array
    {
        return $this->getProvider($provider)->getModels();
    }

    /**
     * Prüft, ob ein Provider verfügbar ist
     * 
     * @param string $provider Der Name des Providers
     * @return bool True, falls der Provider verfügbar ist
     */
    public function hasProvider(string $provider): bool
    {
        return isset($this->providers[$provider]);
    }

    /**
     * Prüft, ob ein Modell für einen Provider verfügbar ist
     * 
     * @param string $provider Der Name des Providers
     * @param string $model Der Name des Modells
     * @return bool True, falls das Modell verfügbar ist
     */
    public function hasModel(string $provider, string $model): bool
    {
        if (!$this->hasProvider($provider)) {
            return false;
        }
        
        return $this->getProvider($provider)->hasModel($model);
    }

    /**
     * Gibt die Standard-Konfiguration zurück
     * 
     * @return array Array mit defaultProvider und defaultModel
     */
    public function getDefaultConfiguration(): array
    {
        return [
            'provider' => $this->defaultProvider,
            'model' => $this->defaultModel
        ];
    }

    /**
     * Erstellt eine neue LLMConfiguration für einen User
     * 
     * @param User $user Der User
     * @param string $provider Der Provider
     * @param string $model Das Modell
     * @param string|null $apiKey Der API-Key (optional)
     * @param string|null $customProviderName Der Name des benutzerdefinierten Providers (optional)
     * @param string|null $customApiUrl Die API-URL für benutzerdefinierte Provider (optional)
     * @param bool $isDefault Ob dies die Standard-Konfiguration sein soll
     * @return LLMConfiguration Die erstellte Konfiguration
     */
    public function createConfiguration(
        User $user,
        string $provider,
        string $model,
        ?string $apiKey = null,
        ?string $customProviderName = null,
        ?string $customApiUrl = null,
        bool $isDefault = false
    ): LLMConfiguration {
        $config = new LLMConfiguration();
        $config->setUser($user);
        $config->setProvider($provider);
        $config->setModel($model);
        $config->setApiKey($apiKey);
        $config->setCustomProviderName($customProviderName);
        $config->setCustomApiUrl($customApiUrl);
        $config->setIsDefault($isDefault);
        
        $this->configRepo->save($config, true);
        
        return $config;
    }

    /**
     * Aktualisiert eine bestehende LLMConfiguration
     * 
     * @param LLMConfiguration $config Die zu aktualisierende Konfiguration
     * @param array $data Die neuen Daten
     * @return LLMConfiguration Die aktualisierte Konfiguration
     */
    public function updateConfiguration(LLMConfiguration $config, array $data): LLMConfiguration
    {
        if (isset($data['provider'])) {
            $config->setProvider($data['provider']);
        }
        if (isset($data['model'])) {
            $config->setModel($data['model']);
        }
        if (isset($data['apiKey'])) {
            $config->setApiKey($data['apiKey']);
        }
        if (isset($data['customProviderName'])) {
            $config->setCustomProviderName($data['customProviderName']);
        }
        if (isset($data['customApiUrl'])) {
            $config->setCustomApiUrl($data['customApiUrl']);
        }
        if (isset($data['isDefault'])) {
            $config->setIsDefault($data['isDefault']);
        }
        
        $this->configRepo->save($config, true);
        
        return $config;
    }

    /**
     * Löscht eine LLMConfiguration
     * 
     * @param LLMConfiguration $config Die zu löschende Konfiguration
     */
    public function deleteConfiguration(LLMConfiguration $config): void
    {
        $this->configRepo->remove($config, true);
    }
}
