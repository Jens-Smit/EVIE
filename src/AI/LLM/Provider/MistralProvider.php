<?php

namespace App\AI\LLM\Provider;

use App\AI\LLM\LLMProviderInterface;
use Symfony\AI\Platform\Mistral\MistralPlatform;
use Symfony\AI\Platform\PlatformInterface;

/**
 * Mistral LLM Provider
 * 
 * Implementiert das LLMProviderInterface für Mistral AI.
 */
class MistralProvider implements LLMProviderInterface
{
    /**
     * @var array Liste aller verfügbaren Mistral-Modelle
     */
    private const MODELS = [
        'mistral-tiny-latest',
        'mistral-small-latest',
        'mistral-medium-latest',
        'mistral-large-latest',
        'codestral-latest',
        'mistral-embed',
    ];

    /**
     * @var string Das Standard-Modell
     */
    private const DEFAULT_MODEL = 'mistral-small-latest';

    /**
     * @var string Die Basis-URL für die Mistral API
     */
    private const API_URL = 'https://api.mistral.ai/v1';

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'mistral';
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
        return new MistralPlatform($apiKey);
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
     * Gibt die Embedding-Dimension für Mistral zurück
     */
    public function getEmbeddingDimension(): int
    {
        return 1024; // Mistral Embeddings haben 1024 Dimensionen
    }
}
