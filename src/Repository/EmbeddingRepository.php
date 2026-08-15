<?php

namespace App\Repository;

use App\Entity\Embedding;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class EmbeddingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Embedding::class);
    }

    /**
     * @param array<int, float> $queryVector
     *
     * @return array<int, array{embedding: ?Embedding, similarity: float, distance: mixed}>
     */
    public function findSimilar(string $contentType, array $queryVector, int $limit = 5, float $minSimilarity = 0.5, ?string $userIdentifier = null): array
    {
        $conn = $this->getEntityManager()->getConnection();

        // P0-5 Tenant-Isolation: ist ein userIdentifier gesetzt, wird das
        // Ergebnis auf Embeddings beschraenkt, deren metadata->>'user_identifier'
        // mit dem Tenant uebereinstimmt (oder keinen Tenant-Bezug hat).
        $tenantClause = '';
        $params = [
            'query_vector' => json_encode($queryVector),
            'content_type' => $contentType,
            'limit' => $limit,
        ];

        if (null !== $userIdentifier && '' !== $userIdentifier) {
            $tenantClause = " AND (e.metadata->>'user_identifier' = :user_identifier OR e.metadata->>'user_identifier' IS NULL)";
            $params['user_identifier'] = $userIdentifier;
        }

        $sql = '
            SELECT e.*,
                   (e.vector <=> :query_vector) as distance
            FROM embeddings e
            WHERE e.content_type = :content_type
            ' . $tenantClause . '
            ORDER BY distance ASC
            LIMIT :limit
        ';

        $stmt = $conn->prepare($sql);
        $result = $stmt->executeQuery($params);

        $results = $result->fetchAllAssociative();

        $filtered = [];
        foreach ($results as $row) {
            $similarity = 1 - $row['distance'];
            if ($similarity >= $minSimilarity) {
                $filtered[] = [
                    'embedding' => $this->find($row['id']),
                    'similarity' => $similarity,
                    'distance' => $row['distance'],
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
