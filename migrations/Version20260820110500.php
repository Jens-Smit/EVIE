<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration for EVIE Phase 7: Conversation Engine
 * Creates tables for Conversation and Message entities.
 */
final class Version20260820110500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates conversation and message tables for AI conversation management';
    }

    public function up(Schema $schema): void
    {
        // Create conversation table
        $this->addSql('CREATE TABLE conversation (id VARCHAR(255) NOT NULL, user_id VARCHAR(255) NOT NULL, tenant_id VARCHAR(255) NOT NULL, title VARCHAR(500) DEFAULT NULL, status VARCHAR(50) DEFAULT \'active\', created_at TIMESTAMPTZ NOT NULL, updated_at TIMESTAMPTZ NOT NULL, last_message_at TIMESTAMPTZ DEFAULT NULL, message_count INT DEFAULT 0, token_count INT DEFAULT 0, metadata JSON DEFAULT NULL, context_window_id VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))');
        
        $this->addSql('CREATE INDEX IDX_8D93D649A76ED395 ON conversation (user_id)');
        $this->addSql('CREATE INDEX IDX_8D93D649D87F330 ON conversation (tenant_id)');
        $this->addSql('CREATE INDEX IDX_8D93D64986C2F725 ON conversation (status)');
        $this->addSql('CREATE INDEX IDX_8D93D6493DA5256F ON conversation (created_at)');
        
        $this->addSql('COMMENT ON COLUMN conversation.metadata IS \(DC2Type:json\)');

        // Create message table
        $this->addSql('CREATE TABLE message (id VARCHAR(255) NOT NULL, conversation_id VARCHAR(255) NOT NULL, user_id VARCHAR(255) NOT NULL, role VARCHAR(50) NOT NULL, content TEXT NOT NULL, created_at TIMESTAMPTZ NOT NULL, updated_at TIMESTAMPTZ DEFAULT NULL, token_count INT DEFAULT 0, metadata JSON DEFAULT NULL, attachments JSON DEFAULT NULL, parent_message_id VARCHAR(255) DEFAULT NULL, execution_id VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))');
        
        $this->addSql('CREATE INDEX IDX_9C3C275349283C8 ON message (conversation_id)');
        $this->addSql('CREATE INDEX IDX_9C3C2753A76ED395 ON message (user_id)');
        $this->addSql('CREATE INDEX IDX_9C3C275386C2F725 ON message (created_at)');
        $this->addSql('CREATE INDEX IDX_9C3C275377184430 ON message (role)');
        
        $this->addSql('COMMENT ON COLUMN message.metadata IS \(DC2Type:json\)');
        $this->addSql('COMMENT ON COLUMN message.attachments IS \(DC2Type:json\)');

        // Add foreign key constraints
        $this->addSql('ALTER TABLE conversation ADD CONSTRAINT FK_8D93D649A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE conversation ADD CONSTRAINT FK_8D93D649D87F330 FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE message ADD CONSTRAINT FK_9C3C275349283C8 FOREIGN KEY (conversation_id) REFERENCES conversation (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE message ADD CONSTRAINT FK_9C3C2753A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message DROP CONSTRAINT FK_9C3C2753A76ED395');
        $this->addSql('ALTER TABLE message DROP CONSTRAINT FK_9C3C275349283C8');
        $this->addSql('ALTER TABLE conversation DROP CONSTRAINT FK_8D93D649D87F330');
        $this->addSql('ALTER TABLE conversation DROP CONSTRAINT FK_8D93D649A76ED395');
        $this->addSql('DROP TABLE message');
        $this->addSql('DROP TABLE conversation');
    }
}
