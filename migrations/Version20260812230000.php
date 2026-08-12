<?php
// migrations/Version20260812230000.php

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration für Streaming-Sessions.
 * Erstellt die Tabelle ai_streaming_sessions für die Verwaltung von Streaming-Sessions.
 */
final class Version20260812230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Erstellt die Tabelle für Streaming-Sessions.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ai_streaming_sessions ('
            . 'id BINARY(16) NOT NULL COMMENT "(DC2Type:uuid)",'
            . 'session_id VARCHAR(255) NOT NULL,'
            . 'tool_name VARCHAR(255) NOT NULL,'
            . 'initial_arguments JSON NOT NULL,'
            . 'user_identifier VARCHAR(255) NOT NULL,'
            . 'status VARCHAR(50) NOT NULL DEFAULT "pending",'
            . 'current_progress TEXT DEFAULT NULL,'
            . 'progress_percentage DOUBLE PRECISION DEFAULT NULL,'
            . 'partial_results JSON DEFAULT NULL,'
            . 'final_result JSON DEFAULT NULL,'
            . 'error_data JSON DEFAULT NULL,'
            . 'created_at DATETIME NOT NULL,'
            . 'started_at DATETIME DEFAULT NULL,'
            . 'completed_at DATETIME DEFAULT NULL,'
            . 'updated_at DATETIME DEFAULT NULL,'
            . 'correlation_id VARCHAR(255) DEFAULT NULL,'
            . 'user_id BINARY(16) DEFAULT NULL COMMENT "(DC2Type:uuid)",'
            . 'PRIMARY KEY (id),'
            . 'UNIQUE INDEX UNIQ_STREAMING_SESSION_ID (session_id),'
            . 'INDEX IDX_STREAMING_SESSION_STATUS (status),'
            . 'INDEX IDX_STREAMING_SESSION_USER (user_identifier),'
            . 'INDEX IDX_STREAMING_SESSION_TOOL (tool_name),'
            . 'INDEX IDX_STREAMING_SESSION_CREATED (created_at),'
            . 'INDEX IDX_STREAMING_SESSION_USER_ID (user_id)'
            . ')');

        $this->addSql('ALTER TABLE ai_streaming_sessions '
            . 'ADD CONSTRAINT FK_STREAMING_SESSION_USER '
            . 'FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ai_streaming_sessions');
    }
}
