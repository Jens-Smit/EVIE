<?php

namespace App\AI\LLM;

use Symfony\AI\Platform\PlatformInterface;

/**
 * Interface für LLM-Provider
 * 
 * Jeder LLM-Anbieter (Mistral, OpenAI, Google, Anthropic, Custom) muss dieses Interface implementieren.
 */
interface LLMProviderInterface
{
    /**
     * Gibt den Namen des Providers zurück (z.B. 'mistral', 'openai')
     */
    public function getName(): string;

    /**
     * Gibt alle verfügbaren Modelle dieses Providers zurück
     * 
     * @return array Array von Modell-Namen
     */
    public function getModels(): array;

    /**
     * Gibt das Standard-Modell dieses Providers zurück
     */
    public function getDefaultModel(): string;

    /**
     * Erstellt eine Platform-Instanz für diesen Provider
     * 
     * @param string $apiKey Der API-Key für den Provider
     * @return PlatformInterface Die Symfony AI Platform-Instanz
     */
    public function createPlatform(string $apiKey): PlatformInterface;

    /**
     * Gibt die Basis-URL für die API dieses Providers zurück
     */
    public function getApiUrl(): string;
}
