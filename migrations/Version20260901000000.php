<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Erstellt die secrets-Tabelle für den Secrets-Store (Issue #10).
 * 
 * Diese Migration erstellt die Tabelle für die verschlüsselte Speicherung von
 * Secrets pro Tenant. Jeder Tenant kann eigene Secrets verwalten, die nur
 * für ihn zugänglich sind.
 */
final class Version20260901000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Erstellt die secrets-Tabelle für den Secrets-Store (Issue #10).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE secrets (id SERIAL PRIMARY KEY, user_identifier VARCHAR(255) NOT NULL, key_name VARCHAR(255) NOT NULL, encrypted_value TEXT NOT NULL, scope VARCHAR(100) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_used_by_tool VARCHAR(255) DEFAULT NULL)');
        $this->addSql('CREATE UNIQUE INDEX uniq_secret_user_key ON secrets (user_identifier, key_name)');
        $this->addSql('CREATE INDEX idx_secret_user_key ON secrets (user_identifier, key_name)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE secrets');
    }
}
