<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Erstellt die goal_evaluations-Tabelle für die Strategie-Evaluation (Issue #14).
 */
final class Version20260903000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Erstellt die goal_evaluations-Tabelle für die Strategie-Evaluation (Issue #14).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE goal_evaluations (id SERIAL PRIMARY KEY, goal_id INTEGER NOT NULL, agent_history_id INTEGER DEFAULT NULL, success BOOLEAN NOT NULL, score DOUBLE PRECISION DEFAULT NULL, feedback TEXT DEFAULT NULL, evaluation_details TEXT DEFAULT NULL, evaluated_by VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL)');
        $this->addSql('CREATE INDEX idx_goal_evaluation_goal ON goal_evaluations (goal_id)');
        $this->addSql('CREATE INDEX idx_goal_evaluation_history ON goal_evaluations (agent_history_id)');
        $this->addSql('CREATE INDEX idx_goal_evaluation_created ON goal_evaluations (created_at)');
        $this->addSql('ALTER TABLE goal_evaluations ADD CONSTRAINT FK_GOAL_EVALUATIONS_GOAL FOREIGN KEY (goal_id) REFERENCES agent_goals (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE goal_evaluations ADD CONSTRAINT FK_GOAL_EVALUATIONS_HISTORY FOREIGN KEY (agent_history_id) REFERENCES agent_history (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE goal_evaluations');
    }
}
