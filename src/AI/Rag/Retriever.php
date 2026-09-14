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
        // H-6: Tenant-Isolation ist der Default (fail-safe). Der user_identifier
        // wird aus den Options gelesen und an den VectorStore durchgereicht.
        // Eine tenant-agnostische Suche (fuer globales System-Wissen) ist nur
        // noch explizit ueber 'allow_cross_tenant' => true moeglich, nicht mehr
        // durch das bloesse Weglassen des Identifiers. So kann ein Aufrufer
        // nicht versehentlich ohne Tenant-Filter suchen (C-2 Root-Cause).
        $userIdentifier = $options['user_identifier'] ?? null;
        $allowCrossTenant = (bool) ($options['allow_cross_tenant'] ?? false);

        if ($userIdentifier === null && !$allowCrossTenant) {
            throw new \InvalidArgumentException(
                'Retriever::retrieve() erfordert einen user_identifier oder '
                . 'den expliziten Parameter allow_cross_tenant=true; eine '
                . 'tenant-agnostische Suche ist per Default nicht erlaubt (ADR-004).'
            );
        }

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

    public function retrieveForType(string $query, string $contentType, int $limit = 5, float $minSimilarity = 0.5, ?string $userIdentifier = null, bool $allowCrossTenant = false): RetrievalResult
    {
        return $this->retrieve($query, [
            'content_types' => [$contentType],
            'limit' => $limit,
            'min_similarity' => $minSimilarity,
            'user_identifier' => $userIdentifier,
            'allow_cross_tenant' => $allowCrossTenant,
        ]);
    }
}
