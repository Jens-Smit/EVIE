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
        // Performance (Audit #1 N+1 / #3 Vektor-Suche): die Kandidatenmenge
        // wird per WHERE contentType = ? eingeschraenkt, statt alle Embeddings
        // ueber findBy() in den Speicher zu laden. Bei Postgres zusaetzlich
        // mit nativem JSON-Filter auf den Tenant (metadata->>'user_identifier'),
        // sodass nur relevante Zeilen geladen werden. Bei SQLite (Tests) bleibt
        // die Tenant-Filterung auf PHP-Ebene als Fallback, da SQLite keinen
        // JSON-Pfad-Zugriff in DQL unterstuetzt.
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
     * Laedt die Embedding-Kandidaten fuer findSimilar() mit Content-Typ- und
     * (bei Postgres) Tenant-Filterung auf SQL-Ebene, um die in den Speicher
     * geladene Datenmenge zu minimieren (Audit #1 N+1 / #3 Vektor-Suche).
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