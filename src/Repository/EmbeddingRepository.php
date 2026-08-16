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
     * Aehnliche Embeddings fuer einen Query-Vektor suchen.
     *
     * P0-1 Tenant-Isolation: ist $userIdentifier gesetzt, werden nur
     * Embeddings beruecksichtigt, deren Metadata entweder denselben
     * user_identifier tragen oder gar keinen Tenant-Bezug haben (System-
     * Wissen). So kann Tenant B niemals Kontext von Tenant A empfangen.
     *
     * Die Filterung erfolgt serverseitig auf Repository-Ebene (nicht nur
     * im Aufrufer), sodass jeder Konsument von findSimilar() isoliert
     * ist, auch wenn er den Identifier nur weiterreicht.
     *
     * @param string          $contentType     Zu durchsuchender Content-Typ
     * @param list<int|float> $queryVector     Query-Einbettung
     * @param int             $limit           Max. Anzahl Ergebnisse
     * @param float           $minSimilarity   Mindest-Aehnlichkeit
     * @param string|null     $userIdentifier  Tenant-Filter (P0-1)
     *
     * @return array<int, array{embedding: Embedding, similarity: float, distance: float}>
     */
    public function findSimilar(string $contentType, array $queryVector, int $limit = 5, float $minSimilarity = 0.5, ?string $userIdentifier = null): array
    {
        $candidates = $this->findBy(['contentType' => $contentType]);

        // P0-1: Tenant-Filter auf Ergebnis-Ebene. Doctrine/SQLite kennt
        // keinen JSON-Pfad-Zugriff in findBy(), deshalb wird hier gefiltert.
        // In Postgres wuerde die Query `metadata->>'user_identifier'`
        // verwenden; die logische Semantik (gleicher Tenant ODER System-
        // Wissen ohne Identifier) bleibt identisch.
        if (null !== $userIdentifier) {
            $candidates = array_filter(
                $candidates,
                static function (Embedding $embedding) use ($userIdentifier): bool {
                    $meta = $embedding->getMetadata();
                    $tenant = $meta['user_identifier'] ?? null;

                    // null-Tenant = systemweites Wissen (z. B. globale
                    // Tool-Beschreibungen); weiterhin fuer alle sichtbar.
                    return null === $tenant || $tenant === $userIdentifier;
                },
            );
            // array_filter erhaelt die Schluessel; neu indizieren.
            $candidates = array_values($candidates);
        }

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