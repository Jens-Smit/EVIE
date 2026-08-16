<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration zur Konsolidierung des User-Schemas.
 * Uebertraegt Daten aus user_profiles nach user_profile und passt agent_history an.
 *
 * P0-2: alle SQL-Statements wurden auf PostgreSQL-Syntax umgeschrieben.
 * Zuvor enthielt diese Migration reine MySQL-DDL (AUTO_INCREMENT, LONGTEXT,
 * ENGINE=InnoDB, DROP FOREIGN KEY, DROP INDEX ... ON ...), die in einem
 * ausschliesslich PostgreSQL-basierten Projekt zu Migrationsfehlern fuehrte.
 */
final class Version20260811190100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Konsolidiert user_profiles nach user_profile und passt agent_history an (Postgres-Syntax)';
    }

    public function up(Schema $schema): void
    {
        // 1. Daten aus user_profiles in user_profile uebertragen (nur IDs,
        //    die noch nicht in user_profile existieren). Beide Tabellen
        //    teilen sich den id-Spiegel.
        $this->addSql("INSERT INTO user_profile (id, name, user_identifier, email, preferences) SELECT id, user_identifier, user_identifier, NULL, preferences FROM user_profiles WHERE id NOT IN (SELECT id FROM user_profile)");

        // 2. FK-Constraint in agent_history loesen. Postgres: DROP CONSTRAINT
        //    statt DROP FOREIGN KEY; DROP INDEX ohne ON <table>.
        $this->addSql('ALTER TABLE agent_history DROP CONSTRAINT IF EXISTS FK_F5E912E16B9DD454');
        $this->addSql('DROP INDEX IF EXISTS IDX_F5E912E16B9DD454');

        // 3. user_profile_id-Spalte in user_id umbenennen (IDs bleiben gleich,
        //    da sie kopiert wurden). Postgres: RENAME COLUMN statt CHANGE.
        $this->addSql('ALTER TABLE agent_history RENAME COLUMN user_profile_id TO user_id');

        // 4. FK auf user_profile (nicht user_profiles) setzen.
        $this->addSql('ALTER TABLE agent_history ADD CONSTRAINT FK_F5E912E1A76ED395 FOREIGN KEY (user_id) REFERENCES user_profile (id)');
        $this->addSql('CREATE INDEX IDX_F5E912E1A76ED395 ON agent_history (user_id)');

        // 5. user_profiles Tabelle loeschen (jetzt ohne FK-Referenzen).
        $this->addSql('DROP TABLE user_profiles');

        // 6. agent_history Spalten an Entity-Definition anpassen.
        //    Postgres: TEXT statt LONGTEXT; separate ALTER-Statements
        //    statt einer kombinierten CHANGE-Klausel.
        $this->addSql('ALTER TABLE agent_history ADD COLUMN details TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE agent_history ADD COLUMN sub_agent_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE agent_history DROP COLUMN IF EXISTS agent_name');
        $this->addSql('ALTER TABLE agent_history DROP COLUMN IF EXISTS input');
        $this->addSql('ALTER TABLE agent_history DROP COLUMN IF EXISTS output');
        $this->addSql('ALTER TABLE agent_history DROP COLUMN IF EXISTS status');
        $this->addSql('ALTER TABLE agent_history ALTER COLUMN action TYPE TEXT');
        $this->addSql('ALTER TABLE agent_history ALTER COLUMN action SET NOT NULL');
        $this->addSql('ALTER TABLE agent_history RENAME COLUMN executed_at TO created_at');
        $this->addSql('ALTER TABLE agent_history ALTER COLUMN created_at SET NOT NULL');

        // 7. FK auf sub_agent setzen.
        $this->addSql('ALTER TABLE agent_history ADD CONSTRAINT FK_F5E912E18EBB9F52 FOREIGN KEY (sub_agent_id) REFERENCES sub_agent (id)');
        $this->addSql('CREATE INDEX IDX_F5E912E18EBB9F52 ON agent_history (sub_agent_id)');

        // 8. JSON-/Spalten-Felder korrigieren (Postgres: ALTER COLUMN TYPE).
        $this->addSql('ALTER TABLE decision_log ALTER COLUMN status TYPE VARCHAR(50)');
        $this->addSql('ALTER TABLE decision_log ALTER COLUMN status DROP NOT NULL');
        $this->addSql('ALTER TABLE document ALTER COLUMN file_path TYPE VARCHAR(255)');
        $this->addSql('ALTER TABLE document ALTER COLUMN file_path DROP NOT NULL');
        $this->addSql('ALTER TABLE sub_agent ALTER COLUMN capabilities TYPE JSON');
        $this->addSql('ALTER TABLE sub_agent ALTER COLUMN capabilities DROP NOT NULL');
        $this->addSql('ALTER TABLE sub_agent ALTER COLUMN status TYPE VARCHAR(50)');
        $this->addSql('ALTER TABLE sub_agent ALTER COLUMN status DROP NOT NULL');
        $this->addSql('ALTER TABLE tool_definition ALTER COLUMN schema TYPE JSON');
        $this->addSql('ALTER TABLE tool_definition ALTER COLUMN schema SET NOT NULL');
        $this->addSql('ALTER TABLE tool_definition ALTER COLUMN updated_at DROP NOT NULL');
        $this->addSql('ALTER TABLE user_profile ALTER COLUMN email TYPE VARCHAR(255)');
        $this->addSql('ALTER TABLE user_profile ALTER COLUMN email DROP NOT NULL');
        $this->addSql('ALTER TABLE user_profile ALTER COLUMN preferences TYPE JSON');
        $this->addSql('ALTER TABLE user_profile ALTER COLUMN preferences DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // Rueckwaertsmigration: Struktur wiederherstellen (Postgres-Syntax).
        $this->addSql('ALTER TABLE agent_history DROP CONSTRAINT IF EXISTS FK_F5E912E18EBB9F52');
        $this->addSql('DROP INDEX IF EXISTS IDX_F5E912E18EBB9F52');
        $this->addSql('ALTER TABLE agent_history DROP COLUMN IF EXISTS sub_agent_id');
        $this->addSql('ALTER TABLE agent_history DROP COLUMN IF EXISTS details');
        $this->addSql('ALTER TABLE agent_history RENAME COLUMN created_at TO executed_at');
        $this->addSql('ALTER TABLE agent_history ALTER COLUMN executed_at SET NOT NULL');
        $this->addSql('ALTER TABLE agent_history ADD COLUMN agent_name VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE agent_history ADD COLUMN input TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE agent_history ADD COLUMN output TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE agent_history ADD COLUMN status VARCHAR(50) NOT NULL');

        $this->addSql('ALTER TABLE agent_history DROP CONSTRAINT IF EXISTS FK_F5E912E1A76ED395');
        $this->addSql('DROP INDEX IF EXISTS IDX_F5E912E1A76ED395');
        $this->addSql('ALTER TABLE agent_history RENAME COLUMN user_id TO user_profile_id');

        // user_profiles als Postgres-Tabelle wiederherstellen.
        $this->addSql('CREATE TABLE user_profiles (id SERIAL NOT NULL, user_type VARCHAR(255) NOT NULL, preferences JSON NOT NULL, context_embedding TEXT DEFAULT NULL, user_identifier VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('ALTER TABLE agent_history ADD CONSTRAINT FK_F5E912E16B9DD454 FOREIGN KEY (user_profile_id) REFERENCES user_profiles (id)');
        $this->addSql('CREATE INDEX IDX_F5E912E16B9DD454 ON agent_history (user_profile_id)');
    }
}
