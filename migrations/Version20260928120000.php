<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Luecke 2 (Fixplan Rev. 2): Asset-Modell fuer Bilder/Screenshots.
 * Die Document-Entity hatte bisher nur ein einzelnes filePath-Feld;
 * fuer multimodale Deliverables (z.B. Nutz-Dokumentation mit
 * Screenshots) wird eine 1:n-Relation document_asset benoetigt
 * (Pfad, Alternativtext, Abschnittsbezug, Position).
 *
 * Guard via to_regclass/IF NOT EXISTS: Der CI-Reparatur-Pfad
 * (ci.yml) simuliert eine defekte Datenbank und markiert Migrationen
 * als ausgefuehrt, ohne dass Tabellen existieren; diese Migration
 * muss dann ohne Fehler durchlaufen.
 */
final class Version20260928120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Erzeugt document_asset-Tabelle fuer Bild-/Screenshot-Assets von Dokumenten';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DO $$ BEGIN IF to_regclass(\'document\') IS NOT NULL AND to_regclass(\'document_asset\') IS NULL THEN
            CREATE TABLE document_asset (
                id SERIAL PRIMARY KEY,
                document_id INT NOT NULL,
                file_path VARCHAR(255) NOT NULL,
                alt_text TEXT NULL,
                section_ref VARCHAR(255) NULL,
                position INT NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                CONSTRAINT fk_document_asset_document FOREIGN KEY (document_id) REFERENCES document (id) ON DELETE CASCADE
            );
            CREATE INDEX idx_document_asset_document ON document_asset (document_id);
        END IF; END $$');
        $this->addSql('COMMENT ON COLUMN document_asset.created_at IS \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS document_asset');
    }
}
