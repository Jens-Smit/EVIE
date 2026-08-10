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
     * Hauptmethode - API-Anfragen ausführen
     */
    #[AsTool(
        name: 'api_request',
        description: 'Führt eine HTTP-Anfrage an eine REST API aus. Unterstützt GET, POST, PUT, DELETE, PATCH mit Authentifizierung.'
    )]
    public function __invoke(
        string $method,
        string $url,
        array $data = [],
        array $headers = [],
        array $query = [],
        string $authType = null,
        string $token = null
    ): array {
        // Authentifizierungs-Header hinzufügen
        if ($authType && $token) {
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
        }

        return $this->request($method, $url, $headers, $query, $data);
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
     * Gibt die verfügbaren HTTP-Methoden zurück
     */
    public function listMethods(): array
    {
        return [
            'status' => 'success',
            'methods' => ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'],
            'description' => 'Verfügbare HTTP-Methoden für REST API-Anfragen',
        ];
    }

    /**
     * Testet eine API-Verbindung
     */
    public function testConnection(string $url, array $headers = []): array
    {
        return $this->request('GET', $url, $headers, []);
    }
}
