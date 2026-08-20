<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration for EVIE Phase 8: MCP + Integrations
 * Creates tables for Integration and McpServer entities.
 */
final class Version20260820132900 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates integration and mcp_server tables for Phase 8';
    }

    public function up(Schema $schema): void
    {
        // Create integration table
        $this->addSql('CREATE TABLE integration (id VARCHAR(255) NOT NULL, identifier VARCHAR(255) NOT NULL, type VARCHAR(50) NOT NULL, name VARCHAR(255) NOT NULL, description TEXT DEFAULT NULL, base_url VARCHAR(2048) DEFAULT NULL, configuration JSON NOT NULL, credentials JSON DEFAULT NULL, scopes JSON DEFAULT NULL, capabilities JSON DEFAULT NULL, is_enabled BOOLEAN DEFAULT true, is_connected BOOLEAN DEFAULT false, is_configured BOOLEAN DEFAULT false, created_at TIMESTAMPTZ NOT NULL, updated_at TIMESTAMPTZ NOT NULL, last_connected_at TIMESTAMPTZ DEFAULT NULL, last_error_at TIMESTAMPTZ DEFAULT NULL, last_error TEXT DEFAULT NULL, tenant_id VARCHAR(255) NOT NULL, organization_id VARCHAR(255) DEFAULT NULL, version VARCHAR(50) DEFAULT NULL, metadata JSON DEFAULT NULL, PRIMARY KEY(id))');
        
        $this->addSql('CREATE INDEX IDX_3A5B7C9D7E06 ON integration (identifier)');
        $this->addSql('CREATE INDEX IDX_3A5B7C9D896DB126 ON integration (type)');
        $this->addSql('CREATE INDEX IDX_3A5B7C9CBD87F330 ON integration (tenant_id)');
        $this->addSql('CREATE INDEX IDX_3A5B7C9C86C2F725 ON integration (is_enabled)');
        $this->addSql('CREATE INDEX IDX_3A5B7C9C3DA5256F ON integration (is_connected)');
        $this->addSql('CREATE INDEX IDX_3A5B7C9C3DA5256E ON integration (is_configured)');
        
        $this->addSql('CREATE UNIQUE INDEX UNIQ_3A5B7C9C896DB126BD87F330 ON integration (tenant_id, type, identifier)');
        
        $this->addSql('COMMENT ON COLUMN integration.configuration IS \(DC2Type:json\)');
        $this->addSql('COMMENT ON COLUMN integration.credentials IS \(DC2Type:json\)');
        $this->addSql('COMMENT ON COLUMN integration.scopes IS \(DC2Type:json\)');
        $this->addSql('COMMENT ON COLUMN integration.capabilities IS \(DC2Type:json\)');
        $this->addSql('COMMENT ON COLUMN integration.metadata IS \(DC2Type:json\)');

        // Create mcp_server table
        $this->addSql('CREATE TABLE mcp_server (id VARCHAR(255) NOT NULL, identifier VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, description TEXT DEFAULT NULL, url VARCHAR(2048) NOT NULL, type VARCHAR(50) DEFAULT \'server\', tools JSON NOT NULL, resources JSON DEFAULT NULL, configuration JSON DEFAULT NULL, is_enabled BOOLEAN DEFAULT true, is_connected BOOLEAN DEFAULT false, created_at TIMESTAMPTZ NOT NULL, updated_at TIMESTAMPTZ NOT NULL, last_connected_at TIMESTAMPTZ DEFAULT NULL, last_error_at TIMESTAMPTZ DEFAULT NULL, last_error TEXT DEFAULT NULL, tenant_id VARCHAR(255) NOT NULL, metadata JSON DEFAULT NULL, PRIMARY KEY(id))');
        
        $this->addSql('CREATE INDEX IDX_4B6C8D0E7E06 ON mcp_server (identifier)');
        $this->addSql('CREATE INDEX IDX_4B6C8D0E896DB126 ON mcp_server (type)');
        $this->addSql('CREATE INDEX IDX_4B6C8D0ECBD87F330 ON mcp_server (tenant_id)');
        $this->addSql('CREATE INDEX IDX_4B6C8D0E86C2F725 ON mcp_server (is_enabled)');
        $this->addSql('CREATE INDEX IDX_4B6C8D0E3DA5256F ON mcp_server (is_connected)');
        
        $this->addSql('CREATE UNIQUE INDEX UNIQ_4B6C8D0E896DB126BD87F330 ON mcp_server (tenant_id, identifier)');
        
        $this->addSql('COMMENT ON COLUMN mcp_server.tools IS \(DC2Type:json\)');
        $this->addSql('COMMENT ON COLUMN mcp_server.resources IS \(DC2Type:json\)');
        $this->addSql('COMMENT ON COLUMN mcp_server.configuration IS \(DC2Type:json\)');
        $this->addSql('COMMENT ON COLUMN mcp_server.metadata IS \(DC2Type:json\)');

        // Add foreign key constraints
        $this->addSql('ALTER TABLE integration ADD CONSTRAINT FK_3A5B7C9CBD87F330 FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE integration ADD CONSTRAINT FK_3A5B7C9C3DA5256F FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE SET NULL');
        
        $this->addSql('ALTER TABLE mcp_server ADD CONSTRAINT FK_4B6C8D0ECBD87F330 FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mcp_server DROP CONSTRAINT FK_4B6C8D0ECBD87F330');
        $this->addSql('ALTER TABLE integration DROP CONSTRAINT FK_3A5B7C9C3DA5256F');
        $this->addSql('ALTER TABLE integration DROP CONSTRAINT FK_3A5B7C9CBD87F330');
        $this->addSql('DROP TABLE mcp_server');
        $this->addSql('DROP TABLE integration');
    }
}
