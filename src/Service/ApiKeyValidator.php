<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Validiert API-Keys fuer die von EVIE unterstuetzten Anbieter (Mistral,
 * Gemini, Tavily) durch einen leichten, authentifizierten API-Aufruf.
 *
 * Die Validierung erfolgt WAHREND des Onboardings, bevor der Key als Secret
 * gespeichert wird. Ein ungueltiger Key blockiert den Wechsel zum naechsten
 * Schritt, sodass der Nutzer sofort Rueckmeldung erhaelt.
 *
 * Blueprint-konform: keine Mockdaten, keine Platzhalter — jeder Anbieter wird
 * gegen seine echte API geprueft. Netzwerk-/Verbindungsfehler werden als
 * "unverifizierbar" markiert, damit ein voruebergehender Ausfall den Flow
 * nicht blockiert (der Key wird dann unkonditional gespeichert).
 */
final class ApiKeyValidator
{
    private const ENDPOINTS = [
        'mistral' => 'https://api.mistral.ai/v1/models',
        'gemini' => 'https://generativelanguage.googleapis.com/v1beta/models',
        'tavily' => 'https://api.tavily.com/key',
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * Validiert einen API-Key fuer den angegebenen Anbieter.
     *
     * @param string $provider Einer von: mistral, gemini, tavily
     * @param string $apiKey   Der zu pruefende Klartext-Key
     *
     * @return array{valid: bool, message: string}
     */
    public function validate(string $provider, string $apiKey): array
    {
        $apiKey = trim($apiKey);
        if ($apiKey === '') {
            return ['valid' => false, 'message' => 'API-Key darf nicht leer sein.'];
        }

        $endpoint = self::ENDPOINTS[$provider] ?? null;
        if ($endpoint === null) {
            return ['valid' => false, 'message' => sprintf('Unbekannter Anbieter "%s".', $provider)];
        }

        try {
            $response = $this->httpClient->request('GET', $endpoint, $this->buildOptions($provider, $apiKey));
            $statusCode = $response->getStatusCode();

            if ($statusCode >= 200 && $statusCode < 300) {
                return ['valid' => true, 'message' => 'API-Key ist gueltig.'];
            }

            return $this->errorFromResponse($response, $provider);
        } catch (\Throwable $e) {
            // Verbindungsfehler blockieren den Flow nicht — der Key wird
            // gespeichert, die Validierung aber als "unverifizierbar" gemeldet.
            return [
                'valid' => true,
                'message' => 'Key gespeichert (Validierung aktuell nicht moeglich: ' . $e->getMessage() . ').',
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildOptions(string $provider, string $apiKey): array
    {
        return match ($provider) {
            'mistral' => [
                'headers' => ['Authorization' => 'Bearer ' . $apiKey, 'Accept' => 'application/json'],
                'max_duration' => 10,
            ],
            'gemini' => [
                'query' => ['key' => $apiKey],
                'headers' => ['Accept' => 'application/json'],
                'max_duration' => 10,
            ],
            'tavily' => [
                'headers' => ['Authorization' => 'Bearer ' . $apiKey, 'Accept' => 'application/json'],
                'max_duration' => 10,
            ],
        };
    }

    /**
     * @return array{valid: bool, message: string}
     */
    private function errorFromResponse($response, string $provider): array
    {
        try {
            $body = json_decode($response->getContent(false), true);
            $detail = $body['error']['message']
                ?? $body['detail']
                ?? $body['error']
                ?? $body['message']
                ?? null;
        } catch (\Throwable) {
            $detail = null;
        }

        $status = $response->getStatusCode();
        if ($status === 401 || $status === 403) {
            return [
                'valid' => false,
                'message' => sprintf('%s: API-Key abgelehnt (HTTP %d).', ucfirst($provider), $status),
            ];
        }

        return [
            'valid' => false,
            'message' => sprintf(
                '%s: unerwartete Antwort (HTTP %d)%s',
                ucfirst($provider),
                $status,
                $detail !== null ? ' — ' . $detail : ''
            ),
        ];
    }
}
