<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * EVIE v0.9.5 - Multi-LLM & Extended Features Migration
 *
 * Diese Migration fügt folgende Tabellen und Felder hinzu:
 * - llm_configurations: Speichert User-spezifische LLM-Konfigurationen
 * - user_secrets: Speichert verschlüsselte User-Secrets (API-Keys, Tokens)
 * - secret_requests: Speichert Anfragen für Secrets von Tools
 * - scheduled_tasks: Speichert geplante Aufgaben
 * 
 * Zusätzlich werden folgende Felder zur agent_history Tabelle hinzugefügt:
 * - conversation_id: Gruppen-ID für Unterhaltungen
 * - conversation_order: Reihenfolge in der Unterhaltung
 * - is_user_message: Ob es eine User-Nachricht ist
 * - message_type: Typ der Nachricht (text, system, notification, etc.)
 * - agent_name: Name des Agenten
 * - user_identifier: User-Identifier
 * - is_success: Ob die Aktion erfolgreich war
 * - parent_message_id: ID der Eltern-Nachricht
 * - metadata: Zusätzliche Metadaten (JSON)
 * - content: Inhalt der Nachricht
 */
final class Version20260819000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'EVIE v0.9.5 - Multi-LLM & Extended Features: LLM-Konfigurationen, User-Secrets, Geplante Aufgaben, Conversation-Support';
    }

    public function up(Schema $schema): void
    {
        // 1. LLM Configurations Tabelle
        $this->addSql('CREATE TABLE llm_configurations (id SERIAL PRIMARY KEY, user_id INTEGER NOT NULL, provider VARCHAR(50) NOT NULL, custom_provider_name VARCHAR(100) DEFAULT NULL, custom_api_url VARCHAR(255) DEFAULT NULL, model VARCHAR(100) NOT NULL, api_key VARCHAR(255) DEFAULT NULL, is_default BOOLEAN DEFAULT false NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL)');
        $this->addSql('CREATE INDEX idx_llm_configurations_user_id ON llm_configurations (user_id)');
        $this->addSql('CREATE INDEX idx_llm_configurations_provider ON llm_configurations (provider)');
        $this->addSql('ALTER TABLE llm_configurations ADD CONSTRAINT fk_llm_configurations_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');

        // 2. User Secrets Tabelle
        $this->addSql('CREATE TABLE user_secrets (id SERIAL PRIMARY KEY, user_id INTEGER NOT NULL, key VARCHAR(100) NOT NULL, encrypted_value TEXT NOT NULL, description VARCHAR(255) DEFAULT NULL, tool_name VARCHAR(50) DEFAULT NULL, is_active BOOLEAN DEFAULT true NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL)');
        $this->addSql('CREATE INDEX idx_user_secrets_user_id ON user_secrets (user_id)');
        $this->addSql('CREATE INDEX idx_user_secrets_key ON user_secrets (key)');
        $this->addSql('CREATE INDEX idx_user_secrets_tool_name ON user_secrets (tool_name)');
        $this->addSql('ALTER TABLE user_secrets ADD CONSTRAINT fk_user_secrets_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');

        // 3. Secret Requests Tabelle
        $this->addSql('CREATE TABLE secret_requests (id SERIAL PRIMARY KEY, tool_id INTEGER NOT NULL, secret_key VARCHAR(100) NOT NULL, description TEXT NOT NULL, is_required BOOLEAN DEFAULT true NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL)');
        $this->addSql('CREATE INDEX idx_secret_requests_tool_id ON secret_requests (tool_id)');
        $this->addSql('CREATE INDEX idx_secret_requests_secret_key ON secret_requests (secret_key)');
        $this->addSql('ALTER TABLE secret_requests ADD CONSTRAINT fk_secret_requests_tool_id FOREIGN KEY (tool_id) REFERENCES tool_definitions (id) ON DELETE CASCADE');

        // 4. Scheduled Tasks Tabelle
        $this->addSql('CREATE TABLE scheduled_tasks (id SERIAL PRIMARY KEY, user_id INTEGER NOT NULL, task_description TEXT NOT NULL, task_type VARCHAR(50) NOT NULL, parameters JSON DEFAULT \'[]\' NOT NULL, scheduled_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, executed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, status VARCHAR(50) DEFAULT \'pending\' NOT NULL, result TEXT DEFAULT NULL, error_message TEXT DEFAULT NULL, is_recurring BOOLEAN DEFAULT false NOT NULL, recurrence_pattern VARCHAR(50) DEFAULT NULL, recurrence_interval INTEGER DEFAULT NULL, next_execution_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, is_active BOOLEAN DEFAULT true NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL)');
        $this->addSql('CREATE INDEX idx_scheduled_tasks_user_id ON scheduled_tasks (user_id)');
        $this->addSql('CREATE INDEX idx_scheduled_tasks_status ON scheduled_tasks (status)');
        $this->addSql('CREATE INDEX idx_scheduled_tasks_scheduled_at ON scheduled_tasks (scheduled_at)');
        $this->addSql('CREATE INDEX idx_scheduled_tasks_is_active ON scheduled_tasks (is_active)');
        $this->addSql('ALTER TABLE scheduled_tasks ADD CONSTRAINT fk_scheduled_tasks_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');

        // 5. Agent History Tabelle erweitern
        $this->addSql('ALTER TABLE agent_history ADD conversation_id VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE agent_history ADD conversation_order INTEGER DEFAULT NULL');
        $this->addSql('ALTER TABLE agent_history ADD is_user_message BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE agent_history ADD message_type VARCHAR(50) DEFAULT \'text\' NOT NULL');
        $this->addSql('ALTER TABLE agent_history ADD agent_name VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE agent_history ADD user_identifier VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE agent_history ADD is_success BOOLEAN DEFAULT true NOT NULL');
        $this->addSql('ALTER TABLE agent_history ADD parent_message_id INTEGER DEFAULT NULL');
        $this->addSql('ALTER TABLE agent_history ADD metadata JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE agent_history ADD content TEXT DEFAULT NULL');

        // Indizes für die neuen Felder
        $this->addSql('CREATE INDEX idx_agent_history_conversation_id ON agent_history (conversation_id)');
        $this->addSql('CREATE INDEX idx_agent_history_conversation_order ON agent_history (conversation_order)');
        $this->addSql('CREATE INDEX idx_agent_history_user_identifier ON agent_history (user_identifier)');
        $this->addSql('CREATE INDEX idx_agent_history_parent_message_id ON agent_history (parent_message_id)');
        $this->addSql('CREATE INDEX idx_agent_history_created_at ON agent_history (created_at)');
    }

    public function down(Schema $schema): void
    {
        // 1. Agent History Felder entfernen
        $this->addSql('ALTER TABLE agent_history DROP content');
        $this->addSql('ALTER TABLE agent_history DROP metadata');
        $this->addSql('ALTER TABLE agent_history DROP parent_message_id');
        $this->addSql('ALTER TABLE agent_history DROP is_success');
        $this->addSql('ALTER TABLE agent_history DROP user_identifier');
        $this->addSql('ALTER TABLE agent_history DROP agent_name');
        $this->addSql('ALTER TABLE agent_history DROP message_type');
        $this->addSql('ALTER TABLE agent_history DROP is_user_message');
        $this->addSql('ALTER TABLE agent_history DROP conversation_order');
        $this->addSql('ALTER TABLE agent_history DROP conversation_id');

        // 2. Scheduled Tasks Tabelle entfernen
        $this->addSql('DROP TABLE scheduled_tasks');

        // 3. Secret Requests Tabelle entfernen
        $this->addSql('DROP TABLE secret_requests');

        // 4. User Secrets Tabelle entfernen
        $this->addSql('DROP TABLE user_secrets');

        // 5. LLM Configurations Tabelle entfernen
        $this->addSql('DROP TABLE llm_configurations');
    }
}
