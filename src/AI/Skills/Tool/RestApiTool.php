<?php
// src/AI/Skills/Tool/RestApiTool.php

namespace App\AI\Skills\Tool;

use App\AI\Security\OutboundRequestPolicy;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\HttpClient\Exception\ClientException;

/**
 * Tool für REST API-Anfragen.
 * Erwartet Parameter: method, url, data, headers, query, authType, token
 */
#[AsTool(
    name: 'api_request',
    description: 'Führt HTTP-Anfragen an REST APIs aus. Parameter: method (GET|POST|PUT|DELETE|PATCH), url (String), data (Array, optional), headers (Array, optional), query (Array, optional), authType (bearer|basic|api_key, optional), token (String, optional)'
)]
class RestApiTool
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private ?string $defaultBaseUrl = null,
        private ?OutboundRequestPolicy $outboundRequestPolicy = null,
    ) {
    }

    /**
     * Hauptmethode - HTTP-Anfrage ausführen
     */
    public function __invoke(array $parameters = []): array
    {
        $method = strtoupper($parameters['method'] ?? 'GET');
        $url = $parameters['url'] ?? '';
        $data = $parameters['data'] ?? [];
        $headers = $parameters['headers'] ?? [];
        $query = $parameters['query'] ?? [];
        $authType = $parameters['authType'] ?? null;
        $token = $parameters['token'] ?? null;

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
                default:
                    $headers['Authorization'] = $token;
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

            // H-3: SSRF-Schutz. Jede ausgehende URL wird vor dem Request gegen
            // die OutboundRequestPolicy geprüft (IP-Bereich, DNS-Rebinding). Ist
            // keine Policy injiziert (Tests), wird der Request ohne Prüfung
            // ausgeführt (Backward-Kompatibilität).
            if (null !== $this->outboundRequestPolicy && !$this->outboundRequestPolicy->isUrlAllowed($fullUrl)) {
                return [
                    'status' => 'error',
                    'url' => $fullUrl,
                    'method' => $method,
                    'message' => 'Request blockiert: URL verletzt die SSRF-Richtlinie (OutboundRequestPolicy).',
                ];
            }

            $options = [
                'headers' => array_merge(['Accept' => 'application/json'], $headers),
                'query' => $query,
                // SSRF-Defense: Redirects dürfen nicht automatisch gefolgt werden.
                'max_redirects' => 0,
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
                'data' => $responseData ?? $content,
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
}
