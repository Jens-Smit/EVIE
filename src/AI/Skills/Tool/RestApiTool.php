<?php
// src/AI/Skills/Tool/RestApiTool.php

namespace App\AI\Skills\Tool;

use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\HttpClient\Exception\ClientException;

/**
 * Tool für REST API-Anfragen.
 * Ermöglicht das Ausführen von HTTP-Anfragen an beliebige REST APIs.
 */
class RestApiTool
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private ?string $defaultBaseUrl = null
    ) {
    }

    /**
     * Führt eine GET-Anfrage aus
     */
    #[AsTool(
        name: 'api_get',
        description: 'Führt eine HTTP GET-Anfrage an eine REST API aus.'
    )]
    public function get(
        string $url,
        array $headers = [],
        array $query = []
    ): array {
        return $this->request('GET', $url, $headers, $query);
    }

    /**
     * Führt eine POST-Anfrage aus
     */
    #[AsTool(
        name: 'api_post',
        description: 'Führt eine HTTP POST-Anfrage an eine REST API aus.'
    )]
    public function post(
        string $url,
        array $data = [],
        array $headers = []
    ): array {
        return $this->request('POST', $url, $headers, [], $data);
    }

    /**
     * Führt eine PUT-Anfrage aus
     */
    #[AsTool(
        name: 'api_put',
        description: 'Führt eine HTTP PUT-Anfrage an eine REST API aus.'
    )]
    public function put(
        string $url,
        array $data = [],
        array $headers = []
    ): array {
        return $this->request('PUT', $url, $headers, [], $data);
    }

    /**
     * Führt eine DELETE-Anfrage aus
     */
    #[AsTool(
        name: 'api_delete',
        description: 'Führt eine HTTP DELETE-Anfrage an eine REST API aus.'
    )]
    public function delete(
        string $url,
        array $headers = [],
        array $query = []
    ): array {
        return $this->request('DELETE', $url, $headers, $query);
    }

    /**
     * Führt eine PATCH-Anfrage aus
     */
    #[AsTool(
        name: 'api_patch',
        description: 'Führt eine HTTP PATCH-Anfrage an eine REST API aus.'
    )]
    public function patch(
        string $url,
        array $data = [],
        array $headers = []
    ): array {
        return $this->request('PATCH', $url, $headers, [], $data);
    }

    /**
     * Führt eine generische HTTP-Anfrage aus
     */
    private function request(
        string $method,
        string $url,
        array $headers = [],
        array $query = [],
        array $data = []
    ): array {
        try {
            // Base URL hinzufügen, falls nicht bereits in der URL enthalten
            $fullUrl = $url;
            if ($this->defaultBaseUrl && !str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
                $fullUrl = rtrim($this->defaultBaseUrl, '/') . '/' . ltrim($url, '/');
            }

            $options = [
                'headers' => array_merge(['Accept' => 'application/json'], $headers),
                'query' => $query,
            ];

            // Für POST, PUT, PATCH: Body hinzufügen
            if (in_array($method, ['POST', 'PUT', 'PATCH']) && !empty($data)) {
                $options['json'] = $data;
                $options['headers']['Content-Type'] = 'application/json';
            }

            $response = $this->httpClient->request($method, $fullUrl, $options);

            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false);

            // Versuche, die Antwort als JSON zu parsen
            $responseData = null;
            if ($content && json_validate($content)) {
                $responseData = json_decode($content, true);
            }

            return [
                'status' => 'success',
                'status_code' => $statusCode,
                'url' => $fullUrl,
                'method' => $method,
                'headers' => $options['headers'],
                'data' => $responseData ?? $content,
                'raw_content' => $content,
            ];
        } catch (ClientException $e) {
            $response = $e->getResponse();
            return [
                'status' => 'error',
                'status_code' => $response->getStatusCode(),
                'url' => $url,
                'method' => $method,
                'message' => 'Client Error: ' . $e->getMessage(),
                'response' => $response->getContent(false),
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'url' => $url,
                'method' => $method,
                'message' => 'Request Error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Führt eine Anfrage mit Authentifizierung aus
     */
    #[AsTool(
        name: 'api_request_with_auth',
        description: 'Führt eine HTTP-Anfrage mit Authentifizierung (Bearer Token, Basic Auth) aus.'
    )]
    public function requestWithAuth(
        string $method,
        string $url,
        array $data = [],
        array $headers = [],
        string $authType = 'bearer',
        string $token = null
    ): array {
        // Authentifizierungs-Header hinzufügen
        switch (strtolower($authType)) {
            case 'bearer':
                $headers['Authorization'] = 'Bearer ' . $token;
                break;
            case 'basic':
                $headers['Authorization'] = 'Basic ' . base64_encode($token);
                break;
            case 'api_key':
                $headers['X-API-Key'] = $token;
                break;
        }

        return $this->request($method, $url, $headers, [], $data);
    }

    /**
     * Führt eine Anfrage mit OAuth Token aus
     */
    #[AsTool(
        name: 'api_request_with_oauth',
        description: 'Führt eine HTTP-Anfrage mit OAuth Access Token aus.'
    )]
    public function requestWithOAuth(
        string $method,
        string $url,
        array $data = [],
        array $headers = [],
        string $accessToken
    ): array {
        $headers['Authorization'] = 'Bearer ' . $accessToken;
        return $this->request($method, $url, $headers, [], $data);
    }

    /**
     * Testet eine API-Verbindung
     */
    #[AsTool(
        name: 'api_test_connection',
        description: 'Testet die Verbindung zu einer REST API.'
    )]
    public function testConnection(
        string $url,
        array $headers = []
    ): array {
        return $this->request('GET', $url, $headers, []);
    }

    /**
     * Gibt die verfügbaren HTTP-Methoden zurück
     */
    #[AsTool(
        name: 'api_list_methods',
        description: 'Listet alle verfügbaren HTTP-Methoden auf.'
    )]
    public function listMethods(): array
    {
        return [
            'status' => 'success',
            'methods' => ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'],
            'description' => 'Verfügbare HTTP-Methoden für REST API-Anfragen',
        ];
    }
}
