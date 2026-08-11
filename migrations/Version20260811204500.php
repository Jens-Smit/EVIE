<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ergänzt fehlende ToolDefinition-Felder (complexity, dependencies, metadata, approvedAt, rejectedAt)
 * und macht category nullable.
 */
final class Version20260811204500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ergänzt complexity, dependencies, metadata, approved_at, rejected_at in tool_definition und macht category nullable';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tool_definition ADD complexity VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE tool_definition ADD dependencies JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE tool_definition ADD metadata JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE tool_definition ADD approved_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE tool_definition ADD rejected_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE tool_definition CHANGE category_id category_id INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tool_definition DROP COLUMN rejected_at');
        $this->addSql('ALTER TABLE tool_definition DROP COLUMN approved_at');
        $this->addSql('ALTER TABLE tool_definition DROP COLUMN metadata');
        $this->addSql('ALTER TABLE tool_definition DROP COLUMN dependencies');
        $this->addSql('ALTER TABLE tool_definition DROP COLUMN complexity');
        $this->addSql('ALTER TABLE tool_definition CHANGE category_id category_id INT NOT NULL');
    }
}