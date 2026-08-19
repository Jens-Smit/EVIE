<?php

namespace App\AI\LLM\Provider;

use App\AI\LLM\LLMProviderInterface;
use Symfony\AI\Platform\Google\GooglePlatform;
use Symfony\AI\Platform\PlatformInterface;

/**
 * Google LLM Provider (Gemini)
 * 
 * Implementiert das LLMProviderInterface für Google Gemini.
 */
class GoogleProvider implements LLMProviderInterface
{
    /**
     * @var array Liste aller verfügbaren Google-Modelle
     */
    private const MODELS = [
        'gemini-1.0-pro',
        'gemini-1.0-pro-001',
        'gemini-1.5-pro',
        'gemini-1.5-pro-001',
        'gemini-1.5-flash',
        'gemini-1.5-flash-001',
        'gemini-1.5-flash-8b',
        'gemini-embedding-001',
    ];

    /**
     * @var string Das Standard-Modell
     */
    private const DEFAULT_MODEL = 'gemini-1.5-flash';

    /**
     * @var string Die Basis-URL für die Google API
     */
    private const API_URL = 'https://generativelanguage.googleapis.com/v1beta';

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'google';
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
        return new GooglePlatform($apiKey);
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
     * Gibt die Embedding-Dimension für Google zurück
     */
    public function getEmbeddingDimension(): int
    {
        return 768; // Google Embeddings haben 768 Dimensionen
    }
}
