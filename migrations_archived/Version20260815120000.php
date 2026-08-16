<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fuegt user_identifier Spalte auf tool_definitions hinzu (P0-5 Tenant-Isolation).
 *
 * Tools sind pro Tenant isoliert: DynamicToolbox und HitlListener filtern
 * nach diesem Identifier, sodass Tenant A niemals Tools von Tenant B sieht
 * oder freigibt. NULL bleibt erlaubt fuer System-/MCP-Tools ohne Tenant-Bezug.
 */
final class Version20260815120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user_identifier column to tool_definitions for tenant isolation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tool_definitions ADD user_identifier VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_tool_definitions_user_identifier ON tool_definitions (user_identifier)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_tool_definitions_user_identifier');
        $this->addSql('ALTER TABLE tool_definitions DROP user_identifier');
    }
}
