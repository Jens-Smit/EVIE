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
        // Postgres: RENAME COLUMN statt MySQL CHANGE; Backtick-Quoting entfernt.
        $this->addSql('ALTER TABLE tool_definition RENAME COLUMN "schema" TO "tool_schema"');
        $this->addSql('ALTER TABLE tool_definition ALTER COLUMN "tool_schema" SET NOT NULL');
        $this->addSql('ALTER TABLE tool_definition ALTER COLUMN "tool_schema" TYPE JSON');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tool_definition RENAME COLUMN "tool_schema" TO "schema"');
        $this->addSql('ALTER TABLE tool_definition ALTER COLUMN "schema" SET NOT NULL');
        $this->addSql('ALTER TABLE tool_definition ALTER COLUMN "schema" TYPE JSON');
    }
}