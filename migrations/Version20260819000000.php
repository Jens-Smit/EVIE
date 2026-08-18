<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fuegt das onboarding_complete-Flag zur users-Tabelle hinzu (Frontend-Audit F4).
 *
 * Die users-Tabelle wird durch Version20260811000000 erstellt.
 * Diese Migration ergaenzt das onboarding_complete boolean.
 *
 * Frontend-Audit F4: Neuer User hat onboarding_complete=false, sodass der
 * Onboarding-Flow nach Login getriggert werden kann (OnboardingController + Popup).
 */
final class Version20260819000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fuegt onboarding_complete boolean zur users-Tabelle hinzu (Frontend-Audit F4).';
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
