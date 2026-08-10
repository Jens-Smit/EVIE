<?php
// src/AI/Skills/Tool/OAuthTool.php

namespace App\AI\Skills\Tool;

use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\Cache\Adapter\AdapterInterface;

#[AsTool(
    name: 'oauth',
    description: 'OAuth 2.0 authentication management. Actions: authenticate, get_access_token, refresh_token, revoke_token, get_current_token, list_providers, get_provider_config'
)]
class OAuthTool
{
    private array $providers = [];
    private AdapterInterface $cache;
    private HttpClientInterface $httpClient;

    public function __construct(HttpClientInterface $httpClient, AdapterInterface $cache)
    {
        $this->httpClient = $httpClient;
        $this->cache = $cache;
        $this->providers = [
            'linkedin' => ['auth_url' => 'https://www.linkedin.com/oauth/v2/authorization', 'token_url' => 'https://www.linkedin.com/oauth/v2/accessToken', 'scopes' => 'r_liteprofile r_emailaddress w_member_social'],
            'google' => ['auth_url' => 'https://accounts.google.com/o/oauth2/auth', 'token_url' => 'https://oauth2.googleapis.com/token', 'scopes' => 'email profile'],
            'microsoft' => ['auth_url' => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize', 'token_url' => 'https://login.microsoftonline.com/common/oauth2/v2.0/token', 'scopes' => 'User.Read'],
        ];
    }

    public function __invoke(array $parameters = []): array
    {
        $action = $parameters['action'] ?? '';
        try {
            return match ($action) {
                'authenticate' => $this->authenticate($parameters['provider'] ?? '', $parameters['clientId'] ?? '', $parameters['redirectUri'] ?? '', $parameters['state'] ?? '', $parameters['scopes'] ?? []),
                'get_access_token' => $this->getAccessToken($parameters['provider'] ?? '', $parameters['clientId'] ?? '', $parameters['clientSecret'] ?? '', $parameters['code'] ?? '', $parameters['redirectUri'] ?? ''),
                'refresh_token' => $this->refreshToken($parameters['provider'] ?? '', $parameters['clientId'] ?? '', $parameters['clientSecret'] ?? '', $parameters['refreshToken'] ?? ''),
                'revoke_token' => $this->revokeToken($parameters['provider'] ?? '', $parameters['token'] ?? ''),
                'get_current_token' => $this->getCurrentToken($parameters['provider'] ?? '', $parameters['clientId'] ?? ''),
                'list_providers' => $this->listProviders(),
                'get_provider_config' => $this->getProviderConfig($parameters['provider'] ?? ''),
                default => ['status' => 'error', 'message' => 'Unknown action: ' . $action, 'available' => ['authenticate', 'get_access_token', 'refresh_token', 'revoke_token', 'get_current_token', 'list_providers', 'get_provider_config']],
            };
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage(), 'action' => $action];
        }
    }

    private function authenticate(string $provider, string $clientId, string $redirectUri, string $state, array $scopes = []): array
    {
        if (!isset($this->providers[$provider])) return ['status' => 'error', 'message' => 'Unknown provider: ' . $provider, 'available' => array_keys($this->providers)];
        $config = $this->providers[$provider];
        $scopes = !empty($scopes) ? $scopes : explode(' ', $config['scopes']);
        $authUrl = $config['auth_url'] . '?' . http_build_query(['response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => $redirectUri, 'scope' => implode(' ', $scopes), 'state' => $state]);
        $this->cache->set('oauth_state_' . $state, ['provider' => $provider, 'client_id' => $clientId, 'redirect_uri' => $redirectUri, 'created_at' => time()], 3600);
        return ['status' => 'success', 'authorization_url' => $authUrl, 'provider' => $provider, 'state' => $state, 'message' => 'Please visit the authorization URL'];
    }

    private function getAccessToken(string $provider, string $clientId, string $clientSecret, string $code, string $redirectUri): array
    {
        if (!isset($this->providers[$provider])) return ['status' => 'error', 'message' => 'Unknown provider'];
        $config = $this->providers[$provider];
        try {
            $response = $this->httpClient->request('POST', $config['token_url'], ['headers' => ['Content-Type' => 'application/x-www-form-urlencoded'], 'body' => ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirectUri, 'client_id' => $clientId, 'client_secret' => $clientSecret]]);
            $data = json_decode($response->getContent(), true);
            if (isset($data['access_token'])) {
                $cacheKey = 'oauth_token_' . $provider . '_' . $clientId;
                $this->cache->set($cacheKey, ['access_token' => $data['access_token'], 'refresh_token' => $data['refresh_token'] ?? null, 'expires_in' => $data['expires_in'] ?? 3600, 'token_type' => $data['token_type'] ?? 'Bearer', 'scope' => $data['scope'] ?? '', 'created_at' => time()], $data['expires_in'] ?? 3600);
                return ['status' => 'success', 'access_token' => $data['access_token'], 'refresh_token' => $data['refresh_token'] ?? null, 'expires_in' => $data['expires_in'] ?? 3600, 'token_type' => $data['token_type'] ?? 'Bearer', 'provider' => $provider];
            }
            return ['status' => 'error', 'message' => 'Failed to get access token', 'response' => $data];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage(), 'provider' => $provider];
        }
    }

    private function refreshToken(string $provider, string $clientId, string $clientSecret, string $refreshToken): array
    {
        if (!isset($this->providers[$provider])) return ['status' => 'error', 'message' => 'Unknown provider'];
        $config = $this->providers[$provider];
        try {
            $response = $this->httpClient->request('POST', $config['token_url'], ['headers' => ['Content-Type' => 'application/x-www-form-urlencoded'], 'body' => ['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken, 'client_id' => $clientId, 'client_secret' => $clientSecret]]);
            $data = json_decode($response->getContent(), true);
            if (isset($data['access_token'])) {
                $cacheKey = 'oauth_token_' . $provider . '_' . $clientId;
                $this->cache->set($cacheKey, ['access_token' => $data['access_token'], 'refresh_token' => $data['refresh_token'] ?? $refreshToken, 'expires_in' => $data['expires_in'] ?? 3600, 'token_type' => $data['token_type'] ?? 'Bearer', 'created_at' => time()], $data['expires_in'] ?? 3600);
                return ['status' => 'success', 'access_token' => $data['access_token'], 'expires_in' => $data['expires_in'] ?? 3600, 'token_type' => $data['token_type'] ?? 'Bearer', 'provider' => $provider];
            }
            return ['status' => 'error', 'message' => 'Failed to refresh token', 'response' => $data];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage(), 'provider' => $provider];
        }
    }

    private function revokeToken(string $provider, string $token): array
    {
        if (!isset($this->providers[$provider])) return ['status' => 'error', 'message' => 'Unknown provider'];
        $revokeUrl = match ($provider) {'linkedin' => 'https://www.linkedin.com/oauth/v2/revoke', 'google' => 'https://oauth2.googleapis.com/revoke', default => null};
        if (!$revokeUrl) return ['status' => 'error', 'message' => 'Revoke URL not configured for: ' . $provider];
        try {
            $this->httpClient->request('POST', $revokeUrl, ['headers' => ['Content-Type' => 'application/x-www-form-urlencoded'], 'body' => ['token' => $token, 'client_id' => $_ENV['OAUTH_' . strtoupper($provider) . '_CLIENT_ID'] ?? '', 'client_secret' => $_ENV['OAUTH_' . strtoupper($provider) . '_CLIENT_SECRET'] ?? '']]);
            foreach ($this->cache->listKeys() as $key) if (str_starts_with($key, 'oauth_token_' . $provider)) $this->cache->delete($key);
            return ['status' => 'success', 'message' => 'Token revoked', 'provider' => $provider];
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage(), 'provider' => $provider];
        }
    }

    private function getCurrentToken(string $provider, string $clientId): array
    {
        $cacheKey = 'oauth_token_' . $provider . '_' . $clientId;
        $tokenData = $this->cache->get($cacheKey, []);
        if (empty($tokenData)) return ['status' => 'error', 'message' => 'No token found', 'provider' => $provider, 'client_id' => $clientId];
        if (isset($tokenData['expires_in'], $tokenData['created_at']) && ($tokenData['created_at'] + $tokenData['expires_in']) < time()) return ['status' => 'expired', 'message' => 'Token expired', 'provider' => $provider, 'client_id' => $clientId, 'expired_at' => date('Y-m-d H:i:s', $tokenData['created_at'] + $tokenData['expires_in'])];
        return ['status' => 'success', 'access_token' => $tokenData['access_token'], 'token_type' => $tokenData['token_type'] ?? 'Bearer', 'expires_in' => $tokenData['expires_in'] ?? 3600, 'scope' => $tokenData['scope'] ?? '', 'created_at' => date('Y-m-d H:i:s', $tokenData['created_at'] ?? time()), 'provider' => $provider, 'client_id' => $clientId];
    }

    private function listProviders(): array { return ['status' => 'success', 'providers' => array_keys($this->providers), 'count' => count($this->providers)]; }
    private function getProviderConfig(string $provider): array { if (!isset($this->providers[$provider])) return ['status' => 'error', 'message' => 'Unknown provider: ' . $provider, 'available' => array_keys($this->providers)]; return ['status' => 'success', 'provider' => $provider, 'config' => $this->providers[$provider]]; }
}
