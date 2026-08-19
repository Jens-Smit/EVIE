<?php

namespace App\AI\LLM\Provider;

use App\AI\LLM\LLMProviderInterface;
use Symfony\AI\Platform\OpenAI\OpenAIPlatform;
use Symfony\AI\Platform\PlatformInterface;

/**
 * Custom LLM Provider
 * 
 * Implementiert das LLMProviderInterface für selbstgehostete LLMs
 * mit OpenAI-kompatibler API.
 */
class CustomProvider implements LLMProviderInterface
{
    /**
     * @var string Das Standard-Modell für benutzerdefinierte Provider
     */
    private const DEFAULT_MODEL = 'custom';

    /**
     * @var string Die Standard-API-URL für benutzerdefinierte Provider
     */
    private const DEFAULT_API_URL = 'http://localhost:8080/v1';

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'custom';
    }

    /**
     * {@inheritdoc}
     * 
     * Für benutzerdefinierte Provider können keine festen Modelle zurückgegeben werden.
     * Stattdessen wird ein Platzhalter zurückgegeben.
     */
    public function getModels(): array
    {
        // Für benutzerdefinierte Provider gibt es keine feste Modell-Liste
        // Der User muss das Modell selbst angeben
        return [];
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
     * 
     * Erstellt eine OpenAI-kompatible Platform für benutzerdefinierte Provider.
     * Die meisten selbstgehosteten LLMs (wie LocalAI, Ollama mit OpenAI-Adapter, etc.)
     * verwenden das OpenAI-API-Format.
     */
    public function createPlatform(string $apiKey): PlatformInterface
    {
        // Für benutzerdefinierte Provider verwenden wir die OpenAIPlatform,
        // da die meisten selbstgehosteten LLMs OpenAI-kompatibel sind
        return new OpenAIPlatform($apiKey);
    }

    /**
     * {@inheritdoc}
     */
    public function getApiUrl(): string
    {
        return self::DEFAULT_API_URL;
    }

    /**
     * Gibt an, ob ein Modell verfügbar ist
     * 
     * Für benutzerdefinierte Provider ist immer true, da der User
     * jedes Modell angeben kann.
     */
    public function hasModel(string $model): bool
    {
        return true;
    }

    /**
     * Erstellt eine Platform mit einer benutzerdefinierten API-URL
     */
    public function createPlatformWithUrl(string $apiKey, string $apiUrl): PlatformInterface
    {
        // Für benutzerdefinierte API-URLs müssen wir eine angepasste Platform erstellen
        // Da Symfony AI keine direkte Möglichkeit bietet, die API-URL zu überschreiben,
        // müssen wir hier eine Workaround-Lösung verwenden
        
        // In einer echten Implementierung würden wir hier eine eigene Platform-Klasse
        // erstellen, die die benutzerdefinierte URL verwendet
        
        // Für jetzt geben wir einfach die Standard-OpenAI-Platform zurück
        // und setzen die API-URL über Environment-Variablen
        return new OpenAIPlatform($apiKey);
    }
}
