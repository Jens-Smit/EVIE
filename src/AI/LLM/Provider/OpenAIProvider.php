<?php

namespace App\AI\LLM\Provider;

use App\AI\LLM\LLMProviderInterface;
use Symfony\AI\Platform\OpenAI\OpenAIPlatform;
use Symfony\AI\Platform\PlatformInterface;

/**
 * OpenAI LLM Provider
 * 
 * Implementiert das LLMProviderInterface für OpenAI.
 */
class OpenAIProvider implements LLMProviderInterface
{
    /**
     * @var array Liste aller verfügbaren OpenAI-Modelle
     */
    private const MODELS = [
        'gpt-3.5-turbo',
        'gpt-3.5-turbo-16k',
        'gpt-3.5-turbo-1106',
        'gpt-4',
        'gpt-4-32k',
        'gpt-4-0613',
        'gpt-4-turbo',
        'gpt-4-turbo-2024-04-09',
        'gpt-4o',
        'gpt-4o-mini',
        'text-embedding-3-small',
        'text-embedding-3-large',
    ];

    /**
     * @var string Das Standard-Modell
     */
    private const DEFAULT_MODEL = 'gpt-3.5-turbo';

    /**
     * @var string Die Basis-URL für die OpenAI API
     */
    private const API_URL = 'https://api.openai.com/v1';

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'openai';
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
        return new OpenAIPlatform($apiKey);
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
     * Gibt die Embedding-Dimension für OpenAI zurück
     */
    public function getEmbeddingDimension(string $model): int
    {
        // text-embedding-3-small: 1536
        // text-embedding-3-large: 3072
        if (str_contains($model, 'text-embedding-3-small')) {
            return 1536;
        }
        if (str_contains($model, 'text-embedding-3-large')) {
            return 3072;
        }
        
        // Ältere Modelle
        return 1536;
    }
}
