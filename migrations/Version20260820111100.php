<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration for EVIE Phase 4: Async Agent Infrastructure
 * Creates table for AgentExecution entity.
 */
final class Version20260820111100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates agent_execution table for async agent execution tracking';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE agent_execution (id VARCHAR(255) NOT NULL, user_id VARCHAR(255) NOT NULL, tenant_id VARCHAR(255) NOT NULL, conversation_id VARCHAR(255) DEFAULT NULL, parent_execution_id VARCHAR(255) DEFAULT NULL, agent VARCHAR(255) NOT NULL, status VARCHAR(50) DEFAULT \'created\', created_at TIMESTAMPTZ NOT NULL, started_at TIMESTAMPTZ DEFAULT NULL, completed_at TIMESTAMPTZ DEFAULT NULL, duration INT DEFAULT NULL, error TEXT DEFAULT NULL, retry_count INT DEFAULT 0, metadata JSON DEFAULT NULL, results JSON DEFAULT NULL, idempotency_key VARCHAR(255) DEFAULT NULL, correlation_id VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))');
        
        $this->addSql('CREATE INDEX IDX_A3254C9CB7F330 ON agent_execution (user_id)');
        $this->addSql('CREATE INDEX IDX_A3254C9CBD87F330 ON agent_execution (tenant_id)');
        $this->addSql('CREATE INDEX IDX_A3254C9C3DA5256F ON agent_execution (conversation_id)');
        $this->addSql('CREATE INDEX IDX_A3254C9C727ACA70 ON agent_execution (status)');
        $this->addSql('CREATE INDEX IDX_A3254C9C3DA5256F ON agent_execution (created_at)');
        $this->addSql('CREATE INDEX IDX_A3254C9C727ACA70 ON agent_execution (parent_execution_id)');
        
        $this->addSql('COMMENT ON COLUMN agent_execution.metadata IS \(DC2Type:json\)');
        $this->addSql('COMMENT ON COLUMN agent_execution.results IS \(DC2Type:json\)');

        // Add foreign key constraints
        $this->addSql('ALTER TABLE agent_execution ADD CONSTRAINT FK_A3254C9CB7F330 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE agent_execution ADD CONSTRAINT FK_A3254C9CBD87F330 FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE agent_execution ADD CONSTRAINT FK_A3254C9C3DA5256F FOREIGN KEY (conversation_id) REFERENCES conversation (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE agent_execution ADD CONSTRAINT FK_A3254C9C727ACA70 FOREIGN KEY (parent_execution_id) REFERENCES agent_execution (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE agent_execution DROP CONSTRAINT FK_A3254C9C727ACA70');
        $this->addSql('ALTER TABLE agent_execution DROP CONSTRAINT FK_A3254C9C3DA5256F');
        $this->addSql('ALTER TABLE agent_execution DROP CONSTRAINT FK_A3254C9CBD87F330');
        $this->addSql('ALTER TABLE agent_execution DROP CONSTRAINT FK_A3254C9CB7F330');
        $this->addSql('DROP TABLE agent_execution');
    }
}
