<?php

namespace App\AI\Skills\Tool;

use Symfony\AI\Agent\Tool\AsTool;

/**
 * Beispiel-Tool für Wetterdaten.
 * Demonstriert die Implementierung eines EVIE-Tools.
 */
#[AsTool(
    name: 'weather',
    description: 'Liefert Wetterdaten für eine Stadt.'
)]
final class WeatherTool
{
    public function __invoke(array $parameters = []): array
    {
        $city = $parameters['city'] ?? 'Berlin';
        
        // Hier würde die echte Wetter-API aufgerufen werden
        return [
            'weather' => 'Sonnig',
            'temperature' => 22,
            'city' => $city,
        ];
    }
}