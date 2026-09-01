<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration für Issue #21: SSO/OIDC + RBAC auf Organisationsebene
 * Fügt neue Felder zu User hinzu und erstellt Organization-Tabelle
 */
final class Version20260905000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds organization_id, sso_provider, sso_id to User entity and creates Organization table for SSO/OIDC + RBAC support';
    }

    public function up(Schema $schema): void
    {
        // This up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE organizations (id SERIAL NOT NULL, name VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, description TEXT DEFAULT NULL, is_active BOOLEAN DEFAULT true, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, settings JSON DEFAULT NULL, rbac_config JSON DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D6495E237E06 ON organizations (slug)');
        $this->addSql('ALTER TABLE users ADD organization_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD sso_provider VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD sso_id VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // This down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE users DROP organization_id');
        $this->addSql('ALTER TABLE users DROP sso_provider');
        $this->addSql('ALTER TABLE users DROP sso_id');
        $this->addSql('DROP TABLE organizations');
    }
}
