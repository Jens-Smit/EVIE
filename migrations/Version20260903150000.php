<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add organization_id to ai_mcp_server_definitions for tenant isolation (C-3).
 */
final class Version20260903150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add organization_id to ai_mcp_server_definitions for tenant isolation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ai_mcp_server_definitions ADD organization_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_mcp_server_organization ON ai_mcp_server_definitions (organization_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_mcp_server_organization');
        $this->addSql('ALTER TABLE ai_mcp_server_definitions DROP organization_id');
    }
}
