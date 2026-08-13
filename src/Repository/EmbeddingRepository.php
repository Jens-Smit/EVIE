<?php

namespace AppRepository;

use AppEntityEmbedding;
use DoctrineBundleDoctrineBundleRepositoryServiceEntityRepository;
use DoctrinePersistenceManagerRegistry;

class EmbeddingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Embedding::class);
    }

    public function findSimilar(string $contentType, array $queryVector, int $limit = 5, float $minSimilarity = 0.5): array
    {
        $conn = $this->getEntityManager()->getConnection();
        
        $sql = '
            SELECT e.*, 
                   (e.vector <=> :query_vector) as distance
            FROM embeddings e
            WHERE e.content_type = :content_type
            ORDER BY distance ASC
            LIMIT :limit
        ';
        
        $stmt = $conn->prepare($sql);
        $stmt->executeStatement([
            'query_vector' => json_encode($queryVector),
            'content_type' => $contentType,
            'limit' => $limit
        ]);
        
        $results = $stmt->fetchAllAssociative();
        
        $filtered = [];
        foreach ($results as $row) {
            $similarity = 1 - $row['distance'];
            if ($similarity >= $minSimilarity) {
                $filtered[] = [
                    'embedding' => $this->find($row['id']),
                    'similarity' => $similarity,
                    'distance' => $row['distance']
                ];
            }
        }
        
        return $filtered;
    }

    public function findByContentHash(string $hash): ?Embedding
    {
        return $this->findOneBy(['contentHash' => $hash]);
    }

    public function saveOrUpdate(Embedding $embedding): Embedding
    {
        $existing = $this->findByContentHash($embedding->getContentHash());
        
        if ($existing) {
            $existing->setVector($embedding->getVector());
            $existing->setMetadata(array_merge($existing->getMetadata(), $embedding->getMetadata()));
            return $existing;
        }
        
        $this->getEntityManager()->persist($embedding);
        $this->getEntityManager()->flush();
        
        return $embedding;
    }

    public function deleteByContentTypeAndSource(string $contentType, string $source): int
    {
        return $this->createQueryBuilder('e')
            ->delete()
            ->where('e.contentType = :contentType')
            ->andWhere('e.source = :source')
            ->setParameter('contentType', $contentType)
            ->setParameter('source', $source)
            ->getQuery()
            ->execute();
    }
}
