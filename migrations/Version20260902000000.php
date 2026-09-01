<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Erstellt die agent_goals-Tabelle für autonome Agent-Ziele (Issue #13).
 */
final class Version20260902000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Erstellt die agent_goals-Tabelle für autonome Agent-Ziele (Issue #13).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE agent_goals (id SERIAL PRIMARY KEY, user_identifier VARCHAR(255) NOT NULL, title VARCHAR(255) NOT NULL, description TEXT DEFAULT NULL, cron_expression VARCHAR(100) DEFAULT NULL, status VARCHAR(50) NOT NULL, last_run_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, next_run_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, capability_constraints JSON DEFAULT NULL, last_result JSON DEFAULT NULL, execution_count INTEGER DEFAULT 0, requires_approval BOOLEAN DEFAULT false, is_approved BOOLEAN DEFAULT false, user_profile_id INTEGER DEFAULT NULL)');
        $this->addSql('CREATE INDEX idx_agent_goal_user ON agent_goals (user_identifier)');
        $this->addSql('CREATE INDEX idx_agent_goal_status ON agent_goals (status)');
        $this->addSql('CREATE INDEX idx_agent_goal_next_run ON agent_goals (next_run_at)');
        $this->addSql('ALTER TABLE agent_goals ADD CONSTRAINT FK_AGENT_GOALS_USER_PROFILE FOREIGN KEY (user_profile_id) REFERENCES user_profile (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE agent_goals');
    }
}
