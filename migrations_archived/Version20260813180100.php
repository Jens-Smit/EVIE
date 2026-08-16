<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates embeddings table for Phase 4: RAG
 */
final class Version20260813180100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates embeddings table for RAG Vector Store';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE EXTENSION IF NOT EXISTS vector');
        $this->addSql('CREATE TABLE embeddings (id SERIAL NOT NULL, content_hash VARCHAR(255) NOT NULL, content TEXT NOT NULL, content_type VARCHAR(100) NOT NULL, source VARCHAR(255) DEFAULT NULL, metadata JSON NOT NULL, vector FLOAT[] NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_embedding_content_hash ON embeddings (content_hash)');
        $this->addSql('CREATE INDEX idx_embedding_content_type ON embeddings (content_type)');
        $this->addSql('CREATE INDEX idx_embedding_source ON embeddings (source)');
        $this->addSql('CREATE INDEX idx_embedding_vector ON embeddings USING GIN (vector vector_l2_ops)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE embeddings');
    }
}