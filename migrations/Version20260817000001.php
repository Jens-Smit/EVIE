<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P2-3: Migriert die embeddings.vector-Spalte von JSON auf den echten
 * pgvector vector(1024)-Typ und ergaenzt einen HNSW-Index fuer
 * approximative Naechste-Nachbar-Suche.
 *
 * Die JSON-gespeicherten Vektoren ([0.1, 0.2, ...]) werden per
 * ::vector-Cast konvertiert. Die Dimension 1024 entspricht dem
 * Mistral-Embedding-Modell (MistralEmbeddingService::DIMENSION).
 *
 * Dies ermoeglicht echtes pgvector-SQL ohne Laufzeit-Cast und einen
 * ANN-Index fuer performante Aehnlichkeitssuche bei grossem Datenvolumen.
 */
final class Version20260817000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'P2-3: Migriert embeddings.vector von JSON auf pgvector vector(1024) + HNSW-Index';
    }

    public function up(Schema $schema): void
    {
        // pgvector-Erweiterung sicherstellen
        $this->addSql('CREATE EXTENSION IF NOT EXISTS vector');

        // Bestehende JSON-Daten als Text zwischenspeichern, Spalte auf
        // vector(1024) umstellen, Daten konvertieren.
        // pgvector akzeptiert das Format [0.1,0.2,...] als Text-Literal.
        $this->addSql("UPDATE embeddings SET vector = vector::text");
        $this->addSql('ALTER TABLE embeddings ALTER COLUMN vector TYPE vector(1024) USING vector::text::vector(1024)');

        // HNSW-Index fuer Cosine-Similarity (vector_cosine_ops)
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_embedding_vector_hnsw ON embeddings USING hnsw (vector vector_cosine_ops)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_embedding_vector_hnsw');
        // Zurueck auf JSON
        $this->addSql('ALTER TABLE embeddings ALTER COLUMN vector TYPE JSON USING vector::text::json');
    }
}
