<?php

namespace AppAIRag;

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
        
        $allResults = [];
        foreach ($contentTypes as $contentType) {
            $results = $this->vectorStore->search($query, $contentType, $limit, $minSimilarity);
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
