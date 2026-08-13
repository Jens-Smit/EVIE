<?php

namespace AppAIRag;

use SymfonyContractsHttpClientHttpClientInterface;

class MistralEmbeddingService implements EmbeddingServiceInterface
{
    private const API_URL = 'https://api.mistral.ai/v1/embeddings';
    private const MODEL = 'mistral-embedding';
    private const DIMENSION = 1024;

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $apiKey
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
            return $data['data'][0]['embedding'] ?? [];
        } catch (Exception $e) {
            // Fallback: Dummy-Vektor für Entwicklung
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
        } catch (Exception $e) {
            // Fallback
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
}
