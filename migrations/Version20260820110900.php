<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration for EVIE Phase 5: Scheduled Tasks
 * Creates table for ScheduledTask entity with tenant isolation.
 */
final class Version20260820110900 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates scheduled_task table for task scheduling with tenant isolation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE scheduled_task (id VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, description TEXT DEFAULT NULL, status VARCHAR(50) DEFAULT \'pending\', schedule JSON NOT NULL, action VARCHAR(50) NOT NULL, parameters JSON DEFAULT NULL, created_at TIMESTAMPTZ NOT NULL, updated_at TIMESTAMPTZ NOT NULL, next_run_at TIMESTAMPTZ DEFAULT NULL, last_run_at TIMESTAMPTZ DEFAULT NULL, run_count INT DEFAULT 0, failure_count INT DEFAULT 0, last_status VARCHAR(50) DEFAULT NULL, last_error TEXT DEFAULT NULL, last_result JSON DEFAULT NULL, metadata JSON DEFAULT NULL, is_active BOOLEAN DEFAULT true, timezone VARCHAR(50) DEFAULT \'UTC\', user_id VARCHAR(255) NOT NULL, tenant_id VARCHAR(255) NOT NULL, organization_id VARCHAR(255) DEFAULT NULL, lock_id VARCHAR(255) DEFAULT NULL, locked_at TIMESTAMPTZ DEFAULT NULL, PRIMARY KEY(id))');
        
        $this->addSql('CREATE INDEX IDX_9C3C2753A76ED395 ON scheduled_task (user_id)');
        $this->addSql('CREATE INDEX IDX_9C3C2753D87F330 ON scheduled_task (tenant_id)');
        $this->addSql('CREATE INDEX IDX_9C3C27533DA5256F ON scheduled_task (organization_id)');
        $this->addSql('CREATE INDEX IDX_9C3C275386C2F725 ON scheduled_task (status)');
        $this->addSql('CREATE INDEX IDX_9C3C27537618E64 ON scheduled_task (next_run_at)');
        $this->addSql('CREATE INDEX IDX_9C3C27533DA5256F ON scheduled_task (created_at)');
        
        $this->addSql('COMMENT ON COLUMN scheduled_task.schedule IS \(DC2Type:json\)');
        $this->addSql('COMMENT ON COLUMN scheduled_task.parameters IS \(DC2Type:json\)');
        $this->addSql('COMMENT ON COLUMN scheduled_task.last_result IS \(DC2Type:json\)');
        $this->addSql('COMMENT ON COLUMN scheduled_task.metadata IS \(DC2Type:json\)');

        // Add foreign key constraints
        $this->addSql('ALTER TABLE scheduled_task ADD CONSTRAINT FK_9C3C2753A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE scheduled_task ADD CONSTRAINT FK_9C3C2753D87F330 FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE scheduled_task ADD CONSTRAINT FK_9C3C27533DA5256F FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE scheduled_task DROP CONSTRAINT FK_9C3C27533DA5256F');
        $this->addSql('ALTER TABLE scheduled_task DROP CONSTRAINT FK_9C3C2753D87F330');
        $this->addSql('ALTER TABLE scheduled_task DROP CONSTRAINT FK_9C3C2753A76ED395');
        $this->addSql('DROP TABLE scheduled_task');
    }
}
