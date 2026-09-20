<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Reparatur-Migration fuer Datenbanken, die in einem inkonsistenten Zustand
 * bootstrapped wurden: Die Migrationskette wurde mehrfach zurueckgesetzt
 * (Commits aaf441c, 652bb46); aeltere Stände der Kette haben nur die
 * Sequenzen (users_id_seq, user_profile_id_seq) bzw. user_profile OHNE
 * users-Tabelle und ohne user_id-Spalte erstellt. Auf solchen Datenbanken
 * ist die Baseline-Migration (Version20260902205052) bereits als ausgefuehrt
 * vermerkt und wird uebersprungen, sodass users/user_profile dauerhaft fehlen
 * (Doctrine\DBAL\Exception\TableNotFoundException: relation "users" does not
 * exist).
 *
 * Diese Migration erstellt users und user_profile idempotent nach:
 *  - Auf intakten/frischen Datenbanken ist sie ein No-Op (IF NOT EXISTS),
 *    der CI-Migrations-Job bleibt gruen.
 *  - Auf defekten Datenbanken werden Tabellen, Spalten, Unique-Indizes und
 *    der Fremdschluessel user_profile.user_id -> users.id (wieder) erstellt.
 *
 * Alle Spalten entsprechen exakt der Baseline-Migration bzw. den Entities
 * (User, UserProfile), damit doctrine:schema:validate In-Sync bleibt.
 */
final class Version20260920120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Stellt fehlende Tabellen users und user_profile idempotent her (Reparatur-Migration)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE IF NOT EXISTS users_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE IF NOT EXISTS user_profile_id_seq INCREMENT BY 1 MINVALUE 1 START 1');

        $this->addSql('CREATE TABLE IF NOT EXISTS users (id INT NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, first_name VARCHAR(100) NOT NULL, last_name VARCHAR(100) NOT NULL, is_active BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_login_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, onboarding_complete BOOLEAN DEFAULT false NOT NULL, organization_id VARCHAR(255) DEFAULT NULL, sso_provider VARCHAR(255) DEFAULT NULL, sso_id VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE TABLE IF NOT EXISTS user_profile (id INT NOT NULL, user_id INT DEFAULT NULL, name VARCHAR(255) DEFAULT NULL, user_identifier VARCHAR(255) NOT NULL, email VARCHAR(255) DEFAULT NULL, preferences JSON DEFAULT NULL, user_type VARCHAR(255) DEFAULT NULL, context_embedding TEXT DEFAULT NULL, onboarding_data JSON DEFAULT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');

        $this->addSql('ALTER TABLE users ADD COLUMN IF NOT EXISTS id INT NOT NULL');
        $this->addSql('ALTER TABLE users ADD COLUMN IF NOT EXISTS email VARCHAR(180) NOT NULL');
        $this->addSql('ALTER TABLE users ADD COLUMN IF NOT EXISTS roles JSON NOT NULL');
        $this->addSql('ALTER TABLE users ADD COLUMN IF NOT EXISTS password VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE users ADD COLUMN IF NOT EXISTS first_name VARCHAR(100) NOT NULL');
        $this->addSql('ALTER TABLE users ADD COLUMN IF NOT EXISTS last_name VARCHAR(100) NOT NULL');
        $this->addSql('ALTER TABLE users ADD COLUMN IF NOT EXISTS is_active BOOLEAN NOT NULL');
        $this->addSql('ALTER TABLE users ADD COLUMN IF NOT EXISTS created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL');
        $this->addSql('ALTER TABLE users ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD COLUMN IF NOT EXISTS last_login_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD COLUMN IF NOT EXISTS onboarding_complete BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE users ADD COLUMN IF NOT EXISTS organization_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD COLUMN IF NOT EXISTS sso_provider VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD COLUMN IF NOT EXISTS sso_id VARCHAR(255) DEFAULT NULL');

        $this->addSql('ALTER TABLE user_profile ADD COLUMN IF NOT EXISTS id INT NOT NULL');
        $this->addSql('ALTER TABLE user_profile ADD COLUMN IF NOT EXISTS user_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE user_profile ADD COLUMN IF NOT EXISTS name VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE user_profile ADD COLUMN IF NOT EXISTS user_identifier VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE user_profile ADD COLUMN IF NOT EXISTS email VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE user_profile ADD COLUMN IF NOT EXISTS preferences JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE user_profile ADD COLUMN IF NOT EXISTS user_type VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE user_profile ADD COLUMN IF NOT EXISTS context_embedding TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE user_profile ADD COLUMN IF NOT EXISTS onboarding_data JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE user_profile ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');

        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS UNIQ_1483A5E9E7927C74 ON users (email)');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS UNIQ_D95AB405D0494586 ON user_profile (user_identifier)');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS UNIQ_D95AB405A76ED395 ON user_profile (user_id)');

        $this->addSql('COMMENT ON COLUMN users.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN users.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN users.last_login_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN user_profile.updated_at IS \'(DC2Type:datetime_immutable)\'');

        $this->addSql('DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE lower(conname) = lower(\'FK_D95AB405A76ED395\') AND conrelid = \'user_profile\'::regclass) THEN ALTER TABLE user_profile ADD CONSTRAINT FK_D95AB405A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) NOT DEFERRABLE INITIALLY IMMEDIATE; END IF; END $$');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_profile DROP CONSTRAINT IF EXISTS FK_D95AB405A76ED395');
    }
}
