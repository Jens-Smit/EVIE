<?php

namespace App\Infrastructure\Mistral;

use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[Autoconfigure(tags: ['ai.mistral_client'])]
class MistralClient
{
    private HttpClientInterface $httpClient;
    private string $apiKey;
    private string $apiUrl;

    public function __construct(
        HttpClientInterface $httpClient,
        string $apiKey,
        string $apiUrl = 'https://api.mistral.ai'
    ) {
        $this->httpClient = $httpClient;
        $this->apiKey = $apiKey;
        $this->apiUrl = rtrim($apiUrl, '/');
    }

    /**
     * Sends a prompt to the Mistral API and returns the response.
     */
    public function chat(string $prompt, array $context = []): array
    {
        $requestData = [
            'model' => 'mistral-medium',
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $this->formatPrompt($prompt, $context),
                ],
            ],
        ];

        $response = $this->httpClient->request('POST', $this->apiUrl . '/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode($requestData),
        ]);

        $content = $response->getContent();
        $data = json_decode($content, true);

        if (isset($data['choices'][0]['message']['content'])) {
            return [
                'response' => $data['choices'][0]['message']['content'],
                'usage' => $data['usage'] ?? [],
            ];
        }

        throw new \RuntimeException('Invalid response from Mistral API: ' . $content);
    }

    /**
     * Formats the prompt with context for the LLM.
     */
    private function formatPrompt(string $prompt, array $context): string
    {
        $contextString = '';
        
        if (!empty($context)) {
            $contextString = "Kontext: " . json_encode($context, JSON_UNESCAPED_UNICODE) . "\n\n";
        }

        return $contextString . "Prompt: " . $prompt;
    }

    /**
     * Generates a JSON schema for a tool based on a description.
     */
    public function generateToolSchema(string $toolName, string $description): array
    {
        $prompt = sprintf(
            "Generiere ein JSON-Schema für ein Tool mit dem Namen '%s' und der Beschreibung: '%s'. " .
            "Das Schema sollte die Eigenschaften, Typen und Beschreibungen der Parameter enthalten. " .
            "Gib nur das JSON-Schema zurück, ohne zusätzliche Erklärungen.",
            $toolName,
            $description
        );

        $response = $this->chat($prompt);
        $schemaString = $response['response'] ?? '{}';

        // Extract JSON from response (in case it's wrapped in markdown or other text)
        if (preg_match('/\{[^}]*\}/s', $schemaString, $matches)) {
            $schemaString = $matches[0];
        }

        $schema = json_decode($schemaString, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Failed to decode tool schema: ' . json_last_error_msg());
        }

        return $schema;
    }
}
