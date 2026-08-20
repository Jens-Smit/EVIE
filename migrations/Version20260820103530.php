<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration for EVIE Phase 1: Security Hardening
 * Creates tables for:
 * - tenant (Multi-tenant support)
 * - organization (Tenant hierarchy)
 * - user (User management with tenant isolation)
 * - user_secret (Encrypted secret storage)
 */
final class Version20260820103530 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates tenant, organization, user, and user_secret tables for multi-tenant security';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE tenant (id VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, slug VARCHAR(255) DEFAULT NULL, configuration JSON DEFAULT NULL, created_at TIMESTAMPTZ NOT NULL, updated_at TIMESTAMPTZ NOT NULL, is_active BOOLEAN DEFAULT true, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D6495E237E06 ON tenant (name)');
        $this->addSql('CREATE INDEX IDX_8D93D64986C2F725 ON tenant (slug)');
        $this->addSql('CREATE TABLE organization (id VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, slug VARCHAR(255) DEFAULT NULL, configuration JSON DEFAULT NULL, created_at TIMESTAMPTZ NOT NULL, updated_at TIMESTAMPTZ NOT NULL, is_active BOOLEAN DEFAULT true, tenant_id VARCHAR(255) NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_9A24B3A786C2F725 ON organization (slug)');
        $this->addSql('CREATE INDEX IDX_9A24B3A7766F5287 ON organization (name)');
        $this->addSql('CREATE INDEX IDX_9A24B3A7D87F330 ON organization (tenant_id)');
        $this->addSql('CREATE TABLE "user" (id VARCHAR(255) NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) DEFAULT NULL, first_name VARCHAR(255) DEFAULT NULL, last_name VARCHAR(255) DEFAULT NULL, created_at TIMESTAMPTZ NOT NULL, updated_at TIMESTAMPTZ NOT NULL, is_active BOOLEAN DEFAULT true, tenant_id VARCHAR(255) NOT NULL, organization_id VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649E7927C74 ON "user" (email)');
        $this->addSql('CREATE INDEX IDX_8D93D649766F5287 ON "user" (first_name)');
        $this->addSql('CREATE INDEX IDX_8D93D649D87F330 ON "user" (tenant_id)');
        $this->addSql('CREATE INDEX IDX_8D93D6493DA5256F ON "user" (organization_id)');
        $this->addSql('CREATE TABLE user_secret (id VARCHAR(255) NOT NULL, user_id VARCHAR(255) NOT NULL, secret_key VARCHAR(255) NOT NULL, encrypted_value TEXT NOT NULL, encryption_version VARCHAR(50) NOT NULL, key_version VARCHAR(50) NOT NULL, created_at TIMESTAMPTZ NOT NULL, updated_at TIMESTAMPTZ NOT NULL, metadata JSON DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_9C3C2753769B012B ON user_secret (user_id, secret_key)');
        $this->addSql('CREATE INDEX IDX_9C3C2753A76ED395 ON user_secret (user_id)');
        $this->addSql('CREATE INDEX IDX_9C3C275377184430 ON user_secret (secret_key)');
        $this->addSql('COMMENT ON COLUMN "user".roles IS \(DC2Type:json\)');
        $this->addSql('COMMENT ON COLUMN tenant.configuration IS \(DC2Type:json\)');
        $this->addSql('COMMENT ON COLUMN organization.configuration IS \(DC2Type:json\)');
        $this->addSql('COMMENT ON COLUMN user_secret.metadata IS \(DC2Type:json\)');

        // Add foreign key constraints
        $this->addSql('ALTER TABLE organization ADD CONSTRAINT FK_9A24B3A7D87F330 FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE "user" ADD CONSTRAINT FK_8D93D649D87F330 FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE "user" ADD CONSTRAINT FK_8D93D6493DA5256F FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE user_secret ADD CONSTRAINT FK_9C3C2753A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE user_secret DROP CONSTRAINT FK_9C3C2753A76ED395');
        $this->addSql('ALTER TABLE "user" DROP CONSTRAINT FK_8D93D6493DA5256F');
        $this->addSql('ALTER TABLE "user" DROP CONSTRAINT FK_8D93D649D87F330');
        $this->addSql('ALTER TABLE organization DROP CONSTRAINT FK_9A24B3A7D87F330');
        $this->addSql('DROP TABLE tenant');
        $this->addSql('DROP TABLE organization');
        $this->addSql('DROP TABLE "user"');
        $this->addSql('DROP TABLE user_secret');
    }
}
