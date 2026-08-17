<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P2-3: Ergaenzt einen HNSW-Index fuer approximative Naechste-Nachbar-Suche
 * auf der embeddings.vector-Spalte (die bereits als vector(1024) in der
 * Baseline-Migration angelegt wird).
 *
 * Die Dimension 1024 entspricht dem Mistral-Embedding-Modell.
 */
final class Version20260817000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'P2-3: HNSW-Index fuer pgvector Cosine-Similarity auf embeddings.vector';
    }

    public function up(Schema $schema): void
    {
        // pgvector-Erweiterung sicherstellen
        $this->addSql('CREATE EXTENSION IF NOT EXISTS vector');

        // HNSW-Index fuer Cosine-Similarity (vector_cosine_ops)
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_embedding_vector_hnsw ON embeddings USING hnsw (vector vector_cosine_ops)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_embedding_vector_hnsw');
    }
}
