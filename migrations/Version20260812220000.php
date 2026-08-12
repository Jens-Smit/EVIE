<?php
// migrations/Version20260812220000.php

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration für MCP-Server-Definitionen.
 * Erstellt die Tabelle ai_mcp_server_definitions für die dynamische Konfiguration von MCP-Servern.
 */
final class Version20260812220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Erstellt die Tabelle für MCP-Server-Definitionen.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ai_mcp_server_definitions ('
            . 'id BINARY(16) NOT NULL COMMENT "(DC2Type:uuid)",'
            . 'name VARCHAR(255) NOT NULL,'
            . 'type VARCHAR(255) NOT NULL,'
            . 'description TEXT NOT NULL,'
            . 'configuration JSON NOT NULL,'
            . 'is_active TINYINT(1) NOT NULL DEFAULT 1,'
            . 'allowed_tools JSON NOT NULL,'
            . 'blocked_resources JSON NOT NULL,'
            . 'created_at DATETIME NOT NULL,'
            . 'updated_at DATETIME DEFAULT NULL,'
            . 'created_by_id BINARY(16) DEFAULT NULL COMMENT "(DC2Type:uuid)",'
            . 'PRIMARY KEY (id),'
            . 'UNIQUE INDEX UNIQ_MCP_SERVER_NAME (name),'
            . 'INDEX IDX_MCP_SERVER_TYPE (type),'
            . 'INDEX IDX_MCP_SERVER_ACTIVE (is_active),'
            . 'INDEX IDX_MCP_SERVER_CREATED_BY (created_by_id)'
            . ')');

        $this->addSql('ALTER TABLE ai_mcp_server_definitions '
            . 'ADD CONSTRAINT FK_MCP_SERVER_CREATED_BY '
            . 'FOREIGN KEY (created_by_id) REFERENCES user (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ai_mcp_server_definitions');
    }
}
