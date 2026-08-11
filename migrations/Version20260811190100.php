<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration zur Konsolidierung des User-Schemas.
 * Überträgt Daten aus user_profiles nach user_profile und passt agent_history an.
 */
final class Version20260811190100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Konsolidiert user_profiles nach user_profile und passt agent_history an';
    }

    public function up(Schema $schema): void
    {
        // 1. Daten aus user_profiles in user_profile übertragen (falls user_profile Daten enthält, überschreiben nichts)
        $this->addSql("INSERT INTO user_profile (id, name, user_identifier, email, preferences) SELECT id, user_identifier, user_identifier, NULL, preferences FROM user_profiles WHERE id NOT IN (SELECT id FROM user_profile)");

        // 2. FK-Constraint in agent_history auf die richtige Tabelle umsetzen
        $this->addSql('ALTER TABLE agent_history DROP FOREIGN KEY FK_F5E912E16B9DD454');
        $this->addSql('DROP INDEX IDX_F5E912E16B9DD454 ON agent_history');
        
        // 3. user_profile_id-Werte auf die neue Tabelle zeigen (IDs bleiben gleich, da wir sie kopiert haben)
        $this->addSql('ALTER TABLE agent_history CHANGE user_profile_id user_id INT NOT NULL');
        
        // 4. FK auf user_profile (nicht user_profiles) setzen
        $this->addSql('ALTER TABLE agent_history ADD CONSTRAINT FK_F5E912E1A76ED395 FOREIGN KEY (user_id) REFERENCES user_profile (id)');
        $this->addSql('CREATE INDEX IDX_F5E912E1A76ED395 ON agent_history (user_id)');
        
        // 5. user_profiles Tabelle löschen (jetzt ohne FK-Referenzen)
        $this->addSql('DROP TABLE user_profiles');

        // 6. agent_history Spalten an Entity-Definition anpassen
        $this->addSql('ALTER TABLE agent_history ADD details LONGTEXT DEFAULT NULL, ADD sub_agent_id INT DEFAULT NULL, DROP agent_name, DROP input, DROP output, DROP status, CHANGE action action LONGTEXT NOT NULL, CHANGE executed_at created_at DATETIME NOT NULL');
        
        // 7. FK auf sub_agent setzen
        $this->addSql('ALTER TABLE agent_history ADD CONSTRAINT FK_F5E912E18EBB9F52 FOREIGN KEY (sub_agent_id) REFERENCES sub_agent (id)');
        $this->addSql('CREATE INDEX IDX_F5E912E18EBB9F52 ON agent_history (sub_agent_id)');
        
        // 8. JSON-Felder korrigieren
        $this->addSql("ALTER TABLE decision_log CHANGE status status VARCHAR(50) DEFAULT NULL");
        $this->addSql("ALTER TABLE document CHANGE file_path file_path VARCHAR(255) DEFAULT NULL");
        $this->addSql("ALTER TABLE sub_agent CHANGE capabilities capabilities JSON DEFAULT NULL, CHANGE status status VARCHAR(50) DEFAULT NULL");
        $this->addSql("ALTER TABLE tool_definition CHANGE `schema` `schema` JSON NOT NULL, CHANGE updated_at updated_at DATETIME DEFAULT NULL");
        $this->addSql("ALTER TABLE user_profile CHANGE email email VARCHAR(255) DEFAULT NULL, CHANGE preferences preferences JSON DEFAULT NULL");
    }

    public function down(Schema $schema): void
    {
        // Rückwärtsmigration: Struktur wiederherstellen
        $this->addSql('ALTER TABLE agent_history DROP FOREIGN KEY FK_F5E912E18EBB9F52');
        $this->addSql('DROP INDEX IDX_F5E912E18EBB9F52 ON agent_history');
        $this->addSql('ALTER TABLE agent_history DROP COLUMN sub_agent_id, DROP COLUMN details, CHANGE created_at executed_at DATETIME NOT NULL, ADD agent_name VARCHAR(255) NOT NULL, ADD input LONGTEXT DEFAULT NULL, ADD output LONGTEXT DEFAULT NULL, ADD status VARCHAR(50) NOT NULL');
        $this->addSql('ALTER TABLE agent_history DROP FOREIGN KEY FK_F5E912E1A76ED395');
        $this->addSql('DROP INDEX IDX_F5E912E1A76ED395 ON agent_history');
        $this->addSql('ALTER TABLE agent_history DROP COLUMN user_id, ADD COLUMN user_profile_id INT NOT NULL');
        $this->addSql('CREATE TABLE user_profiles (id INT AUTO_INCREMENT NOT NULL, user_type VARCHAR(255) NOT NULL, preferences LONGTEXT NOT NULL, context_embedding LONGTEXT DEFAULT NULL, user_identifier VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE agent_history ADD CONSTRAINT FK_F5E912E16B9DD454 FOREIGN KEY (user_profile_id) REFERENCES user_profiles (id)');
        $this->addSql('CREATE INDEX IDX_F5E912E16B9DD454 ON agent_history (user_profile_id)');
    }
}