<?php

namespace App\Infrastructure\VectorStore;

interface VectorStoreInterface
{
    /**
     * Stores a vector embedding with its associated metadata.
     */
    public function storeEmbedding(string $identifier, array $embedding, array $metadata = []): void;

    /**
     * Retrieves embeddings similar to the given query embedding.
     */
    public function findSimilar(array $queryEmbedding, int $limit = 5): array;

    /**
     * Retrieves an embedding by its identifier.
     */
    public function getEmbedding(string $identifier): ?array;

    /**
     * Updates an existing embedding.
     */
    public function updateEmbedding(string $identifier, array $newEmbedding, array $newMetadata = []): void;

    /**
     * Deletes an embedding by its identifier.
     */
    public function deleteEmbedding(string $identifier): void;
}
