<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ergänzt das parameters-Feld in tool_definition.
 */
final class Version20260811204300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ergänzt parameters-Spalte in tool_definition';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tool_definition ADD parameters JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tool_definition DROP COLUMN parameters');
    }
}