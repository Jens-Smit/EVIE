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
 * Struktur folgt dem Schema-Muster der bestehenden Migrationen
 * (z.B. Version20260902205052): id INT NOT NULL mit separater
 * Sequenz statt SERIAL-Default, Index- und FK-Namen wie von Doctrine
 * erwartet (IDX_...), damit doctrine:schema:validate (CI) In-Sync
 * bleibt.
 *
 * Guard via to_regclass: Der CI-Reparatur-Pfad (ci.yml) simuliert eine
 * defekte Datenbank und markiert Migrationen als ausgefuehrt, ohne
 * dass Tabellen existieren; diese Migration muss dann ohne Fehler
 * durchlaufen (auch der Typ-Kommentar steht daher im Guard-Block).
 */
final class Version20260928120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Erzeugt document_asset-Tabelle fuer Bild-/Screenshot-Assets von Dokumenten';
    }

    public function up(Schema $schema): void
    {
        $sql = <<<'SQL'
DO $$ BEGIN
IF to_regclass('document') IS NOT NULL AND to_regclass('document_asset') IS NULL THEN
    CREATE SEQUENCE document_asset_id_seq INCREMENT BY 1 MINVALUE 1 START 1;
    CREATE TABLE document_asset (
        id INT NOT NULL,
        document_id INT NOT NULL,
        file_path VARCHAR(255) NOT NULL,
        alt_text TEXT DEFAULT NULL,
        section_ref VARCHAR(255) DEFAULT NULL,
        position INT NOT NULL,
        created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
        PRIMARY KEY(id)
    );
    CREATE INDEX IDX_6B0E2C2EC33F7837 ON document_asset (document_id);
    ALTER TABLE document_asset ADD CONSTRAINT fk_document_asset_document FOREIGN KEY (document_id) REFERENCES document (id) ON DELETE CASCADE;
    COMMENT ON COLUMN document_asset.created_at IS '(DC2Type:datetime_immutable)';
END IF;
END $$;
SQL;
        $this->addSql($sql);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP SEQUENCE IF EXISTS document_asset_id_seq CASCADE');
        $this->addSql('DROP TABLE IF EXISTS document_asset');
    }
}
