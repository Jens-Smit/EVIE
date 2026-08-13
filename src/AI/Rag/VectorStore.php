<?php

namespace AppAIRag;

use AppEntityEmbedding;
use AppRepositoryEmbeddingRepository;
use DoctrineORMEntityManagerInterface;

class VectorStore
{
    public function __construct(
        private EmbeddingRepository $embeddingRepository,
        private EntityManagerInterface $entityManager,
        private EmbeddingServiceInterface $embeddingService
    ) {
    }

    public function store(string $content, string $contentType, string $source, array $metadata = []): Embedding
    {
        $hash = hash('sha256', $content);
        $existing = $this->embeddingRepository->findByContentHash($hash);
        
        if ($existing) {
            $existing->setMetadata(array_merge($existing->getMetadata(), $metadata));
            $this->entityManager->flush();
            return $existing;
        }

        $vector = $this->embeddingService->embedText($content);

        $embedding = new Embedding();
        $embedding->setContent($content);
        $embedding->setContentType($contentType);
        $embedding->setSource($source);
        $embedding->setMetadata($metadata);
        $embedding->setVector($vector);

        $this->entityManager->persist($embedding);
        $this->entityManager->flush();

        return $embedding;
    }

    public function storeBatch(array $contents, string $contentType, string $source): array
    {
        $texts = array_map(fn($c) => $c['content'], $contents);
        $vectors = $this->embeddingService->embedTextBatch($texts);

        $embeddings = [];
        foreach ($contents as $i => $contentData) {
            $hash = hash('sha256', $contentData['content']);
            $existing = $this->embeddingRepository->findByContentHash($hash);
            
            if ($existing) {
                $existing->setMetadata(array_merge($existing->getMetadata(), $contentData['metadata'] ?? []));
                $embeddings[] = $existing;
                continue;
            }

            $embedding = new Embedding();
            $embedding->setContent($contentData['content']);
            $embedding->setContentType($contentType);
            $embedding->setSource($source);
            $embedding->setMetadata($contentData['metadata'] ?? []);
            $embedding->setVector($vectors[$i] ?? []);

            $this->entityManager->persist($embedding);
            $embeddings[] = $embedding;
        }

        $this->entityManager->flush();
        return $embeddings;
    }

    public function search(string $query, string $contentType, int $limit = 5, float $minSimilarity = 0.5): array
    {
        $queryVector = $this->embeddingService->embedText($query);
        return $this->embeddingRepository->findSimilar($contentType, $queryVector, $limit, $minSimilarity);
    }

    public function delete(string $contentType, string $source): int
    {
        return $this->embeddingRepository->deleteByContentTypeAndSource($contentType, $source);
    }

    public function clear(): int
    {
        return $this->embeddingRepository->createQueryBuilder('e')
            ->delete()
            ->getQuery()
            ->execute();
    }

    public function getStats(): array
    {
        $conn = $this->entityManager->getConnection();
        $countByType = $conn->fetchAllAssociative(
            'SELECT content_type, COUNT(*) as count FROM embeddings GROUP BY content_type'
        );
        
        $total = array_sum(array_column($countByType, 'count'));
        
        return [
            'total' => $total,
            'by_type' => array_reduce($countByType, fn($acc, $row) => $acc + [$row['content_type'] => (int)$row['count']], []),
            'dimension' => $this->embeddingService->getDimension(),
            'model' => $this->embeddingService->getModelName(),
        ];
    }
}
