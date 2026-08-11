<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Benennt die reservierte Spalte "schema" in "tool_schema" um (MariaDB-Kompatibilität).
 */
final class Version20260811204700 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Benennt schema-Spalte in tool_schema um (MariaDB-Kompatibilität)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tool_definition CHANGE `schema` `tool_schema` JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tool_definition CHANGE `tool_schema` `schema` JSON NOT NULL');
    }
}