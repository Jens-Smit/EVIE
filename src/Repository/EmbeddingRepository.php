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

    public function findSimilar(string $contentType, array $queryVector, int $limit = 5, float $minSimilarity = 0.5): array
    {
        $candidates = $this->findBy(['contentType' => $contentType]);

        $queryEmbedding = new Embedding();
        $queryEmbedding->setVector($queryVector);

        $scored = [];
        foreach ($candidates as $embedding) {
            $similarity = $embedding->cosineSimilarity($queryEmbedding);
            if ($similarity >= $minSimilarity) {
                $scored[] = [
                    'embedding' => $embedding,
                    'similarity' => $similarity,
                    'distance' => 1 - $similarity,
                ];
            }
        }

        usort($scored, fn($a, $b) => $b['similarity'] <=> $a['similarity']);

        return array_slice($scored, 0, $limit);
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