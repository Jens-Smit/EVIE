<?php
// src/AI/Skills/Tool/OAuthTool.php

namespace App\AI\Skills\Tool;

use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\Cache\Adapter\AdapterInterface;

/**
 * Tool für OAuth 2.0 Authentifizierung.
 * Verwaltet Access Tokens, Refresh Tokens und OAuth-Flows für verschiedene Anbieter.
 */
class OAuthTool
{
    private array $providers = [];
    private AdapterInterface $cache;

    public function __construct(
        HttpClientInterface $httpClient,
        AdapterInterface $cache
    ) {
        $this->cache = $cache;
        
        // Standard-OAuth-Provider-Konfigurationen
        $this->providers = [
            'linkedin' => [
                'auth_url' => 'https://www.linkedin.com/oauth/v2/authorization',
                'token_url' => 'https://www.linkedin.com/oauth/v2/accessToken',
                'scopes' => 'r_liteprofile r_emailaddress w_member_social',
            ],
            'google' => [
                'auth_url' => 'https://accounts.google.com/o/oauth2/auth',
                'token_url' => 'https://oauth2.googleapis.com/token',
                'scopes' => 'email profile',
            ],
            'microsoft' => [
                'auth_url' => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
                'token_url' => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
                'scopes' => 'User.Read',
            ],
        ];
    }

    /**
     * Führt den OAuth-Authentifizierungsflow durch
     */
    #[AsTool(
        name: 'oauth_authenticate',
        description: 'Führt den OAuth-Authentifizierungsflow für einen Anbieter durch und gibt die Autorisierungs-URL zurück.'
    )]
    public function authenticate(
        string $provider,
        string $clientId,
        string $redirectUri,
        string $state,
        array $scopes = []
    ): array {
        if (!isset($this->providers[$provider])) {
            return [
                'status' => 'error',
                'message' => 'Unbekannter OAuth-Anbieter: ' . $provider,
                'available_providers' => array_keys($this->providers),
            ];
        }

        $providerConfig = $this->providers[$provider];
        $scopes = !empty($scopes) ? $scopes : explode(' ', $providerConfig['scopes']);

        $authUrl = $providerConfig['auth_url'] . '?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scope' => implode(' ', $scopes),
            'state' => $state,
        ]);

        // State im Cache speichern für spätere Validierung
        $this->cache->set('oauth_state_' . $state, [
            'provider' => $provider,
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'created_at' => time(),
        ], 3600); // 1 Stunde Gültigkeit

        return [
            'status' => 'success',
            'authorization_url' => $authUrl,
            'provider' => $provider,
            'state' => $state,
            'message' => 'Bitte besuche die Autorisierungs-URL und gib den Authorization Code ein.',
        ];
    }

    /**
     * Tauscht den Authorization Code gegen ein Access Token
     */
    #[AsTool(
        name: 'oauth_get_access_token',
        description: 'Tauscht den OAuth Authorization Code gegen ein Access Token und Refresh Token.'
    )]
    public function getAccessToken(
        string $provider,
        string $clientId,
        string $clientSecret,
        string $code,
        string $redirectUri
    ): array {
        if (!isset($this->providers[$provider])) {
            return [
                'status' => 'error',
                'message' => 'Unbekannter OAuth-Anbieter: ' . $provider,
            ];
        }

        $providerConfig = $this->providers[$provider];

        try {
            $response = $this->httpClient->request('POST', $providerConfig['token_url'], [
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'body' => [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $redirectUri,
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                ],
            ]);

            $data = json_decode($response->getContent(), true);

            if (isset($data['access_token'])) {
                // Token im Cache speichern
                $cacheKey = 'oauth_token_' . $provider . '_' . $clientId;
                $this->cache->set($cacheKey, [
                    'access_token' => $data['access_token'],
                    'refresh_token' => $data['refresh_token'] ?? null,
                    'expires_in' => $data['expires_in'] ?? 3600,
                    'token_type' => $data['token_type'] ?? 'Bearer',
                    'scope' => $data['scope'] ?? '',
                    'created_at' => time(),
                ], $data['expires_in'] ?? 3600);

                return [
                    'status' => 'success',
                    'access_token' => $data['access_token'],
                    'refresh_token' => $data['refresh_token'] ?? null,
                    'expires_in' => $data['expires_in'] ?? 3600,
                    'token_type' => $data['token_type'] ?? 'Bearer',
                    'provider' => $provider,
                ];
            } else {
                return [
                    'status' => 'error',
                    'message' => 'Fehler beim Abrufen des Access Tokens',
                    'response' => $data,
                ];
            }
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Fehler beim OAuth-Token-Austausch: ' . $e->getMessage(),
                'provider' => $provider,
            ];
        }
    }

    /**
     * Erneuert ein abgelaufenes Access Token
     */
    #[AsTool(
        name: 'oauth_refresh_token',
        description: 'Erneuert ein abgelaufenes OAuth Access Token mit dem Refresh Token.'
    )]
    public function refreshToken(
        string $provider,
        string $clientId,
        string $clientSecret,
        string $refreshToken
    ): array {
        if (!isset($this->providers[$provider])) {
            return [
                'status' => 'error',
                'message' => 'Unbekannter OAuth-Anbieter: ' . $provider,
            ];
        }

        $providerConfig = $this->providers[$provider];

        try {
            $response = $this->httpClient->request('POST', $providerConfig['token_url'], [
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'body' => [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refreshToken,
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                ],
            ]);

            $data = json_decode($response->getContent(), true);

            if (isset($data['access_token'])) {
                // Neues Token im Cache speichern
                $cacheKey = 'oauth_token_' . $provider . '_' . $clientId;
                $this->cache->set($cacheKey, [
                    'access_token' => $data['access_token'],
                    'refresh_token' => $data['refresh_token'] ?? $refreshToken,
                    'expires_in' => $data['expires_in'] ?? 3600,
                    'token_type' => $data['token_type'] ?? 'Bearer',
                    'created_at' => time(),
                ], $data['expires_in'] ?? 3600);

                return [
                    'status' => 'success',
                    'access_token' => $data['access_token'],
                    'expires_in' => $data['expires_in'] ?? 3600,
                    'token_type' => $data['token_type'] ?? 'Bearer',
                    'provider' => $provider,
                ];
            } else {
                return [
                    'status' => 'error',
                    'message' => 'Fehler beim Erneuern des Access Tokens',
                    'response' => $data,
                ];
            }
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Fehler beim OAuth-Token-Refresh: ' . $e->getMessage(),
                'provider' => $provider,
            ];
        }
    }

    /**
     * Widerruft ein OAuth Token
     */
    #[AsTool(
        name: 'oauth_revoke_token',
        description: 'Widerruft ein OAuth Access Token.'
    )]
    public function revokeToken(
        string $provider,
        string $token
    ): array {
        if (!isset($this->providers[$provider])) {
            return [
                'status' => 'error',
                'message' => 'Unbekannter OAuth-Anbieter: ' . $provider,
            ];
        }

        $providerConfig = $this->providers[$provider];
        
        // LinkedIn spezifische Revoke-URL
        if ($provider === 'linkedin') {
            $revokeUrl = 'https://www.linkedin.com/oauth/v2/revoke';
        } elseif ($provider === 'google') {
            $revokeUrl = 'https://oauth2.googleapis.com/revoke';
        } else {
            return [
                'status' => 'error',
                'message' => 'Revoke-URL für Anbieter nicht konfiguriert: ' . $provider,
            ];
        }

        try {
            $response = $this->httpClient->request('POST', $revokeUrl, [
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'body' => [
                    'token' => $token,
                    'client_id' => $_ENV['OAUTH_' . strtoupper($provider) . '_CLIENT_ID'] ?? '',
                    'client_secret' => $_ENV['OAUTH_' . strtoupper($provider) . '_CLIENT_SECRET'] ?? '',
                ],
            ]);

            // Token aus dem Cache entfernen
            $cacheKeys = $this->cache->listKeys();
            foreach ($cacheKeys as $key) {
                if (str_starts_with($key, 'oauth_token_' . $provider)) {
                    $this->cache->delete($key);
                }
            }

            return [
                'status' => 'success',
                'message' => 'OAuth Token erfolgreich widerrufen',
                'provider' => $provider,
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Fehler beim Widerrufen des OAuth Tokens: ' . $e->getMessage(),
                'provider' => $provider,
            ];
        }
    }

    /**
     * Gibt das aktuelle Access Token für einen Anbieter zurück
     */
    #[AsTool(
        name: 'oauth_get_current_token',
        description: 'Gibt das aktuell gespeicherte OAuth Access Token für einen Anbieter zurück.'
    )]
    public function getCurrentToken(
        string $provider,
        string $clientId
    ): array {
        $cacheKey = 'oauth_token_' . $provider . '_' . $clientId;
        $tokenData = $this->cache->get($cacheKey, []);

        if (empty($tokenData)) {
            return [
                'status' => 'error',
                'message' => 'Kein OAuth Token für diesen Anbieter und Client gefunden',
                'provider' => $provider,
                'client_id' => $clientId,
            ];
        }

        // Prüfe, ob Token abgelaufen ist
        if (isset($tokenData['expires_in']) && isset($tokenData['created_at'])) {
            $expiresAt = $tokenData['created_at'] + $tokenData['expires_in'];
            if ($expiresAt < time()) {
                return [
                    'status' => 'expired',
                    'message' => 'OAuth Token ist abgelaufen',
                    'provider' => $provider,
                    'client_id' => $clientId,
                    'expired_at' => date('Y-m-d H:i:s', $expiresAt),
                ];
            }
        }

        return [
            'status' => 'success',
            'access_token' => $tokenData['access_token'],
            'token_type' => $tokenData['token_type'] ?? 'Bearer',
            'expires_in' => $tokenData['expires_in'] ?? 3600,
            'scope' => $tokenData['scope'] ?? '',
            'created_at' => date('Y-m-d H:i:s', $tokenData['created_at'] ?? time()),
            'provider' => $provider,
            'client_id' => $clientId,
        ];
    }

    /**
     * Listet alle konfigurierten OAuth-Anbieter auf
     */
    #[AsTool(
        name: 'oauth_list_providers',
        description: 'Listet alle konfigurierten OAuth-Anbieter auf.'
    )]
    public function listProviders(): array
    {
        return [
            'status' => 'success',
            'providers' => array_keys($this->providers),
            'count' => count($this->providers),
        ];
    }

    /**
     * Gibt die Konfiguration eines OAuth-Anbieters zurück
     */
    #[AsTool(
        name: 'oauth_get_provider_config',
        description: 'Gibt die OAuth-Konfiguration für einen bestimmten Anbieter zurück.'
    )]
    public function getProviderConfig(string $provider): array
    {
        if (!isset($this->providers[$provider])) {
            return [
                'status' => 'error',
                'message' => 'Unbekannter OAuth-Anbieter: ' . $provider,
                'available_providers' => array_keys($this->providers),
            ];
        }

        return [
            'status' => 'success',
            'provider' => $provider,
            'config' => $this->providers[$provider],
        ];
    }
}
