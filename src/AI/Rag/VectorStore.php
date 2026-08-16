<?php

namespace App\AI\Rag;

use App\Entity\Embedding;
use App\Repository\EmbeddingRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\AI\Rag\EmbeddingServiceInterface;
use Symfony\Contracts\Cache\CacheInterface;

class VectorStore
{
    public function __construct(
        private EmbeddingRepository $embeddingRepository,
        private EntityManagerInterface $entityManager,
        private EmbeddingServiceInterface $embeddingService,
        private ?CacheInterface $cache = null,
    ) {
    }

    public function store(string $content, string $contentType, string $source, array $metadata = []): Embedding
    {
        $hash = hash('sha256', $content);
        $existing = $this->findCachedByContentHash($hash);

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
        $this->invalidateContentHashCache($hash);

        return $embedding;
    }

    public function storeBatch(array $contents, string $contentType, string $source): array
    {
        $texts = array_map(fn($c) => $c['content'], $contents);
        $vectors = $this->embeddingService->embedTextBatch($texts);

        $embeddings = [];
        foreach ($contents as $i => $contentData) {
            $hash = hash('sha256', $contentData['content']);
            $existing = $this->findCachedByContentHash($hash);
            
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

    /**
     * P0-5 Tenant-Isolation: ist ein userIdentifier gesetzt, werden nur
     * Embeddings des jeweiligen Tenants (oder ohne Tenant-Bezug) geliefert.
     *
     * @return array<int, array{embedding: ?Embedding, similarity: float, distance: mixed}>
     */
    public function search(string $query, string $contentType, int $limit = 5, float $minSimilarity = 0.5, ?string $userIdentifier = null): array
    {
        $queryVector = $this->embeddingService->embedText($query);
        return $this->embeddingRepository->findSimilar($contentType, $queryVector, $limit, $minSimilarity, $userIdentifier);
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

    /**
     * Cached-Lookup eines Embeddings anhand seines Content-Hash
     * (Audit-Finding #2: Kein Embedding-Cache).
     *
     * Cacht den DB-Lookup in cache.app, sodass wiederholte Suchen nach
     * demselben Content (z. B. bei wiederholten User-Anfragen) nicht jedes
     * Mal die DB bemuehen. Negative Lookups (Hash nicht in DB) werden
     * ebenfalls gecacht, um wiederholte Store-Versuche von bereits als
     * nicht-vorhanden erkannten Inhalten zu beschleunigen.
     */
    private function findCachedByContentHash(string $hash): ?Embedding
    {
        if (null === $this->cache) {
            return $this->embeddingRepository->findByContentHash($hash);
        }

        $key = 'embedding_hash_' . $hash;

        /** @var Embedding|null $result */
        return $this->cache->get($key, function () use ($hash): ?Embedding {
            return $this->embeddingRepository->findByContentHash($hash);
        });
    }

    /**
     * Invalidiert den Cache-Eintrag fuer einen Content-Hash, nachdem ein
     * neues Embedding gespeichert wurde.
     */
    private function invalidateContentHashCache(string $hash): void
    {
        if (null === $this->cache) {
            return;
        }

        $this->cache->delete('embedding_hash_' . $hash);
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
