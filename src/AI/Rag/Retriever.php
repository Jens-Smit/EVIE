<?php

namespace App\AI\Rag;

class Retriever
{
    public function __construct(
        private VectorStore $vectorStore
    ) {
    }

    public function retrieve(string $query, array $options = []): RetrievalResult
    {
        $contentTypes = $options['content_types'] ?? ['user_profile', 'conversation', 'tool_memory', 'knowledge'];
        $limit = $options['limit'] ?? 5;
        $minSimilarity = $options['min_similarity'] ?? 0.5;
        // P0-5 Tenant-Isolation: der user_identifier wird aus den Options
        // gelesen und an den VectorStore durchgereicht, sodass pro Tenant
        // isoliert gesucht wird. Fehlt der Identifier, bleibt die Suche
        // tenant-agnostisch (Rueckwaertskompatibilitaet).
        $userIdentifier = $options['user_identifier'] ?? null;

        $allResults = [];
        foreach ($contentTypes as $contentType) {
            $results = $this->vectorStore->search($query, $contentType, $limit, $minSimilarity, $userIdentifier);
            foreach ($results as $result) {
                $allResults[] = new RetrievedItem(
                    $result['embedding'],
                    $result['similarity'],
                    $contentType
                );
            }
        }

        usort($allResults, fn($a, $b) => $b->similarity <=> $a->similarity);

        return new RetrievalResult($query, array_slice($allResults, 0, $limit));
    }

    public function retrieveForType(string $query, string $contentType, int $limit = 5, float $minSimilarity = 0.5): RetrievalResult
    {
        return $this->retrieve($query, [
            'content_types' => [$contentType],
            'limit' => $limit,
            'min_similarity' => $minSimilarity
        ]);
    }
}
