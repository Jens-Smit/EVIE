<?php

namespace App\AI\LLM\Provider;

use App\AI\LLM\LLMProviderInterface;
use Symfony\AI\Platform\Anthropic\AnthropicPlatform;
use Symfony\AI\Platform\PlatformInterface;

/**
 * Anthropic LLM Provider (Claude)
 * 
 * Implementiert das LLMProviderInterface für Anthropic Claude.
 */
class AnthropicProvider implements LLMProviderInterface
{
    /**
     * @var array Liste aller verfügbaren Anthropic-Modelle
     */
    private const MODELS = [
        'claude-3-opus-20240229',
        'claude-3-sonnet-20240229',
        'claude-3-haiku-20240307',
        'claude-3-5-sonnet-20240620',
        'claude-3-5-sonnet-20241022',
        'claude-2.1',
        'claude-2.0',
        'claude-instant-1.2',
    ];

    /**
     * @var string Das Standard-Modell
     */
    private const DEFAULT_MODEL = 'claude-3-5-sonnet-20241022';

    /**
     * @var string Die Basis-URL für die Anthropic API
     */
    private const API_URL = 'https://api.anthropic.com/v1';

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'anthropic';
    }

    /**
     * {@inheritdoc}
     */
    public function getModels(): array
    {
        return self::MODELS;
    }

    /**
     * {@inheritdoc}
     */
    public function getDefaultModel(): string
    {
        return self::DEFAULT_MODEL;
    }

    /**
     * {@inheritdoc}
     */
    public function createPlatform(string $apiKey): PlatformInterface
    {
        return new AnthropicPlatform($apiKey);
    }

    /**
     * {@inheritdoc}
     */
    public function getApiUrl(): string
    {
        return self::API_URL;
    }

    /**
     * Gibt an, ob ein Modell verfügbar ist
     */
    public function hasModel(string $model): bool
    {
        return in_array($model, self::MODELS, true);
    }

    /**
     * Gibt die maximale Token-Länge für ein Modell zurück
     */
    public function getMaxTokens(string $model): int
    {
        return match ($model) {
            'claude-3-opus-20240229' => 200000,
            'claude-3-sonnet-20240229' => 200000,
            'claude-3-5-sonnet-20240620' => 200000,
            'claude-3-5-sonnet-20241022' => 200000,
            'claude-2.1' => 200000,
            'claude-2.0' => 100000,
            'claude-instant-1.2' => 100000,
            default => 100000,
        };
    }
}
