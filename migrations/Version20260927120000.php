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
 *
 * Guard via to_regclass: Der CI-Reparatur-Pfad (ci.yml) simuliert eine
 * defekte Datenbank, in der nur Sequenzen existieren, und markiert alle
 * Migrationen ausser der Reparatur-Migration als ausgefuehrt; diese
 * Migration muss dann ohne Fehler durchlaufen, statt an der fehlenden
 * document-Tabelle zu scheitern.
 */
final class Version20260927120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fuegt document.status (draft/completed) hinzu';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DO $$ BEGIN IF to_regclass(\'document\') IS NOT NULL THEN ALTER TABLE document ADD COLUMN IF NOT EXISTS status VARCHAR(32) NOT NULL DEFAULT \'completed\'; END IF; END $$');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DO $$ BEGIN IF to_regclass(\'document\') IS NOT NULL THEN ALTER TABLE document DROP COLUMN IF EXISTS status; END IF; END $$');
    }
}
