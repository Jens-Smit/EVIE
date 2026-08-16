<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds new fields to tool_definitions table for Phase 2: Evolution Engine
 */
final class Version20260813170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds executor_type, executor_config, security_policy, hitl_policy, version to tool_definitions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tool_definitions ADD executor_type VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE tool_definitions ADD executor_config JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE tool_definitions ADD security_policy JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE tool_definitions ADD hitl_policy JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE tool_definitions ADD version VARCHAR(50) DEFAULT \'1.0\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tool_definitions DROP COLUMN executor_type');
        $this->addSql('ALTER TABLE tool_definitions DROP COLUMN executor_config');
        $this->addSql('ALTER TABLE tool_definitions DROP COLUMN security_policy');
        $this->addSql('ALTER TABLE tool_definitions DROP COLUMN hitl_policy');
        $this->addSql('ALTER TABLE tool_definitions DROP COLUMN version');
    }
}