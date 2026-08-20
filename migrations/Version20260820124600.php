<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration for EVIE Phase 5: Capability Engine
 * Creates table for Capability entity.
 */
final class Version20260820124600 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates capability table for capability management';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE capability (id VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, identifier VARCHAR(255) NOT NULL, description TEXT DEFAULT NULL, category VARCHAR(50) DEFAULT \'general\', configuration JSON NOT NULL, required_secrets JSON NOT NULL, required_integrations JSON NOT NULL, required_permissions JSON NOT NULL, parameters JSON DEFAULT NULL, is_enabled BOOLEAN DEFAULT true, is_installed BOOLEAN DEFAULT false, is_configured BOOLEAN DEFAULT false, created_at TIMESTAMPTZ NOT NULL, updated_at TIMESTAMPTZ NOT NULL, tenant_id VARCHAR(255) NOT NULL, organization_id VARCHAR(255) DEFAULT NULL, provider VARCHAR(50) DEFAULT NULL, version VARCHAR(255) DEFAULT NULL, metadata JSON DEFAULT NULL, PRIMARY KEY(id))');
        
        $this->addSql('CREATE INDEX IDX_A3254C9C5E237E06 ON capability (name)');
        $this->addSql('CREATE INDEX IDX_A3254C9C896DB126 ON capability (identifier)');
        $this->addSql('CREATE INDEX IDX_A3254C9CBD87F330 ON capability (tenant_id)');
        $this->addSql('CREATE INDEX IDX_A3254C9C3DA5256F ON capability (organization_id)');
        $this->addSql('CREATE INDEX IDX_A3254C9C86C2F725 ON capability (is_enabled)');
        
        $this->addSql('CREATE UNIQUE INDEX UNIQ_A3254C9C896DB126BD87F330 ON capability (identifier, tenant_id)');
        
        $this->addSql('COMMENT ON COLUMN capability.configuration IS \(DC2Type:json\)');
        $this->addSql('COMMENT ON COLUMN capability.required_secrets IS \(DC2Type:json\)');
        $this->addSql('COMMENT ON COLUMN capability.required_integrations IS \(DC2Type:json\)');
        $this->addSql('COMMENT ON COLUMN capability.required_permissions IS \(DC2Type:json\)');
        $this->addSql('COMMENT ON COLUMN capability.parameters IS \(DC2Type:json\)');
        $this->addSql('COMMENT ON COLUMN capability.metadata IS \(DC2Type:json\)');

        // Add foreign key constraints
        $this->addSql('ALTER TABLE capability ADD CONSTRAINT FK_A3254C9CBD87F330 FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE capability ADD CONSTRAINT FK_A3254C9C3DA5256F FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE capability DROP CONSTRAINT FK_A3254C9C3DA5256F');
        $this->addSql('ALTER TABLE capability DROP CONSTRAINT FK_A3254C9CBD87F330');
        $this->addSql('DROP TABLE capability');
    }
}
