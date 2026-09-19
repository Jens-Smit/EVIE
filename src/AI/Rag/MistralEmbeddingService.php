<?php

namespace App\AI\Rag;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class MistralEmbeddingService implements EmbeddingServiceInterface
{
    private const API_URL = 'https://api.mistral.ai/v1/embeddings';
    private const MODEL = 'mistral-embed';
    private const DIMENSION = 1024;

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $apiKey,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function embedText(string $text): array
    {
        try {
            $response = $this->httpClient->request('POST', self::API_URL, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => self::MODEL,
                    'input' => $text,
                ],
            ]);

            $data = json_decode($response->getContent(), true);
            $vector = $data['data'][0]['embedding'] ?? [];
            if ($vector === []) {
                $this->logFailure('Leere Embedding-Antwort von Mistral');
            }
            return $vector;
        } catch (\Exception $e) {
            $this->logFailure($e->getMessage());
            return array_fill(0, self::DIMENSION, 0.0);
        }
    }

    public function embedTextBatch(array $texts): array
    {
        try {
            $response = $this->httpClient->request('POST', self::API_URL, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => self::MODEL,
                    'input' => $texts,
                ],
            ]);

            $data = json_decode($response->getContent(), true);
            $embeddings = [];
            foreach ($data['data'] ?? [] as $item) {
                $embeddings[] = $item['embedding'] ?? [];
            }
            return $embeddings;
        } catch (\Exception $e) {
            $this->logFailure($e->getMessage());
            return array_fill(0, count($texts), array_fill(0, self::DIMENSION, 0.0));
        }
    }

    public function getDimension(): int
    {
        return self::DIMENSION;
    }

    public function getModelName(): string
    {
        return self::MODEL;
    }

    private function logFailure(string $message): void
    {
        $this->logger?->error('Mistral-Embedding fehlgeschlagen - RAG-Kontextsuche liefert keine Treffer', [
            'model' => self::MODEL,
            'error' => $message,
        ]);
    }
}