<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ergänzt fehlende UserProfile-Felder (userType, contextEmbedding, onboardingData, updatedAt)
 * und macht name nullable, damit Chat-Endpunkt und Onboarding-Manager funktionieren.
 */
final class Version20260811202200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ergänzt user_type, context_embedding, onboarding_data, updated_at in user_profile und macht name nullable';
    }

    public function up(Schema $schema): void
    {
        // Bestehende name-Spalte nullable machen
        $this->addSql('ALTER TABLE user_profile CHANGE name name VARCHAR(255) DEFAULT NULL');

        // Neue Felder ergänzen
        $this->addSql('ALTER TABLE user_profile ADD user_type VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE user_profile ADD context_embedding TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE user_profile ADD onboarding_data JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE user_profile ADD updated_at DATETIME DEFAULT NULL');

        // Bestehende Zeilen auf sinnvolle Defaults setzen
        $this->addSql("UPDATE user_profile SET user_type = 'unknown' WHERE user_type IS NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_profile DROP COLUMN updated_at');
        $this->addSql('ALTER TABLE user_profile DROP COLUMN onboarding_data');
        $this->addSql('ALTER TABLE user_profile DROP COLUMN context_embedding');
        $this->addSql('ALTER TABLE user_profile DROP COLUMN user_type');
        $this->addSql('ALTER TABLE user_profile CHANGE name name VARCHAR(255) NOT NULL');
    }
}