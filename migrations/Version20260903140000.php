<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add success_metric, last_evaluation, last_evaluation_score to agent_goals.
 * Diese Felder werden von EvaluationService fuer die Strategie-Bewertung
 * benoetigt (getSuccessMetric/setLastEvaluation/setLastEvaluationScore).
 */
final class Version20260903140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add success_metric, last_evaluation, last_evaluation_score to agent_goals';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE agent_goals ADD success_metric TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE agent_goals ADD last_evaluation TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE agent_goals ADD last_evaluation_score DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('COMMENT ON COLUMN agent_goals.last_evaluation IS \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE agent_goals DROP success_metric');
        $this->addSql('ALTER TABLE agent_goals DROP last_evaluation');
        $this->addSql('ALTER TABLE agent_goals DROP last_evaluation_score');
    }
}
