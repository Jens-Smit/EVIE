<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fuegt der Document-Entity das status-Feld (draft/completed) hinzu.
 * Das StrategyDocumentTool markiert gespeicherte Dokumente entsprechend
 * der Qualitaets-Validierung vor der Auslieferung (Blueprint: kein
 * Erfolg-Simulieren bei Stub-Inhalten).
 */
final class Version20260927120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fuegt document.status (draft/completed) hinzu';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document ADD COLUMN IF NOT EXISTS status VARCHAR(32) NOT NULL DEFAULT \'completed\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document DROP COLUMN IF EXISTS status');
    }
}
