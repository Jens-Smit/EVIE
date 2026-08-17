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
     * P1-A pgvector: auf PostgreSQL wird die Aehnlichkeitsberechnung
     * serverseitig ueber den nativen pgvector-Operator <=> (Cosine-
     * Distanz) ausgefuehrt, anstatt alle Vektoren nach PHP zu laden und
     * dort zu sortieren. Die JSON-gespeicherten Vektoren werden dazu per
     * ::text::vector in den pgvector-Typ gecastet. Auf SQLite (Tests)
     * bleibt die PHP-basierte cosineSimilarity()-Berechnung als Fallback,
     * da SQLite keinen pgvector-Typ kennt.
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
        $conn = $this->getEntityManager()->getConnection();
        $platform = $conn->getDatabasePlatform();

        // P1-A: Auf PostgreSQL den nativen pgvector-Pfad nutzen (Performance).
        if (method_exists($platform, 'getName') && $platform->getName() === 'postgresql') {
            return $this->findSimilarPgVector($contentType, $queryVector, $limit, $minSimilarity, $userIdentifier);
        }

        // SQLite (Tests): PHP-basierte Berechnung (Fallback, kein pgvector).
        return $this->findSimilarInMemory($contentType, $queryVector, $limit, $minSimilarity, $userIdentifier);
    }

    /**
     * P1-A: PostgreSQL-nativer pgvector-Aehnlichkeits-Pfad.
     *
     * Berechnet die Cosine-Distanz (1 - Cosine-Similarity) serverseitig via
     * pgvector-Operator <=>. Die JSON-gespeicherten Vektoren werden per
     * ::text::vector gecastet. Der Tenant-Filter wird serverseitig per
     * JSON-Pfad-Zugriff (metadata->>'user_identifier') angewendet.
     *
     * @param list<int|float> $queryVector
     *
     * @return array<int, array{embedding: Embedding, similarity: float, distance: float}>
     */
    private function findSimilarPgVector(string $contentType, array $queryVector, int $limit, float $minSimilarity, ?string $userIdentifier): array
    {
        $conn = $this->getEntityManager()->getConnection();

        // Query-Vektor als pgvector-Text-Literal: [0.1,0.2,...]
        $queryLiteral = '[' . implode(',', array_map('floatval', $queryVector)) . ']';

        $sql = "SELECT id, 1 - (vector::text::vector <=> :query::vector) AS similarity "
            . "FROM embeddings "
            . "WHERE content_type = :contentType ";

        $params = [
            'query' => $queryLiteral,
            'contentType' => $contentType,
        ];

        if (null !== $userIdentifier) {
            $sql .= "AND (metadata->>'user_identifier' = :userIdentifier OR metadata->>'user_identifier' IS NULL) ";
            $params['userIdentifier'] = $userIdentifier;
        }

        // pgvector liefert Distanz; Min-Similarity-Filter + Sortierung nach
        // Aehnlichkeit absteigend + Limit, alles serverseitig.
        $sql .= "AND 1 - (vector::text::vector <=> :query::vector) >= :minSimilarity "
            . "ORDER BY vector::text::vector <=> :query::vector ASC "
            . "LIMIT :limit";
        $params['minSimilarity'] = $minSimilarity;
        $params['limit'] = $limit;

        $rows = $conn->executeQuery($sql, $params)->fetchAllAssociative();

        if ([] === $rows) {
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            $embedding = $this->find($row['id']);
            if (null === $embedding) {
                continue;
            }
            $similarity = (float) $row['similarity'];
            $result[] = [
                'embedding' => $embedding,
                'similarity' => $similarity,
                'distance' => 1 - $similarity,
            ];
        }

        return $result;
    }

    /**
     * PHP-basierter Fallback fuer SQLite (Tests): laedt Kandidaten und
     * berechnet die Cosine-Similarity in PHP, da SQLite keinen pgvector-
     * Typ unterstuetzt.
     *
     * @param list<int|float> $queryVector
     *
     * @return array<int, array{embedding: Embedding, similarity: float, distance: float}>
     */
    private function findSimilarInMemory(string $contentType, array $queryVector, int $limit, float $minSimilarity, ?string $userIdentifier): array
    {
        $candidates = $this->loadCandidates($contentType, $userIdentifier);

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

    /**
     * Laedt die Embedding-Kandidaten fuer den PHP-Fallback-Pfad mit Content-Typ-
     * und (bei Postgres) Tenant-Filterung auf SQL-Ebene. Wird nur noch vom
     * SQLite-Fallback (findSimilarInMemory) genutzt; der pgvector-Pfad filtert
     * selbst.
     *
     * @return list<Embedding>
     */
    private function loadCandidates(string $contentType, ?string $userIdentifier): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $platform = $conn->getDatabasePlatform();

        // Postgres: nativer JSON-Pfad-Zugriff fuer den Tenant-Filter, sodass
        // nur Zeilen mit passendem user_identifier (oder ohne Tenant-Bezug)
        // geladen werden. Reduziert die Kandidatenmenge bereits serverseitig.
        if (null !== $userIdentifier && method_exists($platform, 'getName') && $platform->getName() === 'postgresql') {
            $sql = "SELECT id FROM embeddings "
                . "WHERE content_type = :contentType "
                . "AND (metadata->>'user_identifier' = :userIdentifier OR metadata->>'user_identifier' IS NULL)";
            $rows = $conn->executeQuery($sql, [
                'contentType' => $contentType,
                'userIdentifier' => $userIdentifier,
            ])->fetchAllAssociative();

            if ([] === $rows) {
                return [];
            }

            $ids = array_column($rows, 'id');

            return $this->findBy(['id' => $ids]);
        }

        // SQLite (Tests) oder ohne Tenant-Filter: Doctrine-Query mit Content-Typ-
        // Filter. Die Tenant-Filterung muss hier auf PHP-Ebene erfolgen, da SQLite
        // keinen JSON-Pfad-Zugriff in DQL unterstuetzt.
        $candidates = $this->findBy(['contentType' => $contentType]);

        if (null === $userIdentifier) {
            return $candidates;
        }

        return array_values(array_filter(
            $candidates,
            static function (Embedding $embedding) use ($userIdentifier): bool {
                $meta = $embedding->getMetadata();
                $tenant = $meta['user_identifier'] ?? null;

                // null-Tenant = systemweites Wissen; weiterhin fuer alle sichtbar.
                return null === $tenant || $tenant === $userIdentifier;
            },
        ));
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
