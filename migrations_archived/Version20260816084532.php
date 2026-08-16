<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260816084532 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE decision_log ALTER context TYPE JSON USING context::json');
        $this->addSql('ALTER TABLE decision_log ALTER approved_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('COMMENT ON COLUMN decision_log.approved_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE tool_definitions ADD category_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE tool_definitions ADD user_identifier VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE tool_definitions DROP category');
        $this->addSql('ALTER TABLE tool_definitions ADD CONSTRAINT FK_95CBB9C12469DE2 FOREIGN KEY (category_id) REFERENCES tool_category (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_95CBB9C12469DE2 ON tool_definitions (category_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tool_definitions DROP CONSTRAINT FK_95CBB9C12469DE2');
        $this->addSql('DROP INDEX IDX_95CBB9C12469DE2');
        $this->addSql('ALTER TABLE tool_definitions ADD category VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE tool_definitions DROP category_id');
        $this->addSql('ALTER TABLE tool_definitions DROP user_identifier');
        $this->addSql('ALTER TABLE decision_log ALTER context TYPE TEXT');
        $this->addSql('ALTER TABLE decision_log ALTER approved_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('COMMENT ON COLUMN decision_log.approved_at IS NULL');
    }
}