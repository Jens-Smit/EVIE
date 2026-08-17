<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Fügt das onboardingComplete-Flag zur users-Tabelle hinzu.
 *
 * Frontend-Audit F4: Neuer User hat kein Onboarding-Flag, sodass der Onboarding-Flow
 * nach Login getriggert werden kann (OnboardingController + Popup).
 */
final class Version20260817000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fügt onboarding_complete boolean column zur users-Tabelle hinzu (Frontend-Audit F4).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD onboarding_complete BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP onboarding_complete');
    }
}
