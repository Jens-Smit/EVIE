<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Initiale Baseline-Migration: legt das vollstaendige finale Schema aus den
 * Doctrine-Entities auf einer leeren PostgreSQL-Datenbank an.
 *
 * Hintergrund (P0-A aus docs/roadmap.md): die bisherige inkrementelle
 * Migrationskette war historisch gewachsen und nicht von einer leeren
 * Datenbank replaybar. Die erste Migration (Version20260811190100) war eine
 * Konsolidierungs-Migration, die Tabellen voraussetzte, die nie per CREATE
 * TABLE angelegt wurden (user_profile, agent_history, decision_log, document,
 * sub_agent, tool_definition(s), tool_category). Zusaetzlich enthielten
 * aeltere Migrationen MySQL-ismen (TINYINT(1)) und fehlerhafte FK-Referenzen
 * (REFERENCES user statt users).
 *
 * Diese Migration konsolidiert das gesamte Schema in einem Schritt und ersetzt
 * die alte inkrementelle Kette. Sie entspricht dem Stand, den
 * `doctrine:schema:validate` gegen die aktuellen Entities erzeugt.
 *
 * Alle folgenden Migrationen (Version20260811190100+) waren Transformationen
 * auf diesem Schema und sind durch diese Baseline obsolet geworden; sie werden
 * als archiviert betrachtet (siehe docs/roadmap.md P0-A).
 */
final class Version20260811000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Baseline: legt das vollstaendige finale Schema aus den Entities an (ersetzt die alte inkrementelle Kette).';
    }

    public function up(Schema $schema): void
    {
        // pgvector-Erweiterung (fuer Embeddings/Vektor-Suche).
        $this->addSql('CREATE EXTENSION IF NOT EXISTS vector');

        // --- users -------------------------------------------------------
        $this->addSql('CREATE TABLE users (id SERIAL NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, first_name VARCHAR(100) NOT NULL, last_name VARCHAR(100) NOT NULL, is_active BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_login_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_users_email ON users (email)');

        // --- user_profile ------------------------------------------------
        $this->addSql('CREATE TABLE user_profile (id SERIAL NOT NULL, name VARCHAR(255) DEFAULT NULL, user_identifier VARCHAR(255) NOT NULL, email VARCHAR(255) DEFAULT NULL, preferences JSON DEFAULT NULL, user_type VARCHAR(255) DEFAULT NULL, context_embedding TEXT DEFAULT NULL, onboarding_data JSON DEFAULT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, user_id INT DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_profile_user_identifier ON user_profile (user_identifier)');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_profile_user_id ON user_profile (user_id)');
        $this->addSql('ALTER TABLE user_profile ADD CONSTRAINT FK_USER_PROFILE_USER FOREIGN KEY (user_id) REFERENCES users (id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        // --- sub_agent ---------------------------------------------------
        // Vor agent_history anlegen, da agent_history.sub_agent_id -> sub_agent referenziert.
        $this->addSql('CREATE TABLE sub_agent (id SERIAL NOT NULL, name VARCHAR(255) NOT NULL, description TEXT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id INT NOT NULL, capabilities JSON DEFAULT NULL, status VARCHAR(50) DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_SUB_AGENT_USER ON sub_agent (user_id)');
        $this->addSql('ALTER TABLE sub_agent ADD CONSTRAINT FK_SUB_AGENT_USER FOREIGN KEY (user_id) REFERENCES user_profile (id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        // --- agent_history ----------------------------------------------
        // Nach sub_agent anlegen, da FK auf sub_agent gesetzt wird.
        $this->addSql('CREATE TABLE agent_history (id SERIAL NOT NULL, action TEXT NOT NULL, details TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id INT NOT NULL, sub_agent_id INT DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_AGENT_HISTORY_USER ON agent_history (user_id)');
        $this->addSql('CREATE INDEX IDX_AGENT_HISTORY_SUB_AGENT ON agent_history (sub_agent_id)');
        $this->addSql('ALTER TABLE agent_history ADD CONSTRAINT FK_AGENT_HISTORY_USER FOREIGN KEY (user_id) REFERENCES user_profile (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE agent_history ADD CONSTRAINT FK_AGENT_HISTORY_SUB_AGENT FOREIGN KEY (sub_agent_id) REFERENCES sub_agent (id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        // --- document ----------------------------------------------------
        // Nach agent_history anlegen, da document.agent_history_id -> agent_history referenziert.
        $this->addSql('CREATE TABLE document (id SERIAL NOT NULL, name VARCHAR(255) NOT NULL, content TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id INT NOT NULL, agent_history_id INT DEFAULT NULL, file_path VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_DOCUMENT_USER ON document (user_id)');
        $this->addSql('CREATE INDEX IDX_DOCUMENT_AGENT_HISTORY ON document (agent_history_id)');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_DOCUMENT_USER FOREIGN KEY (user_id) REFERENCES user_profile (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_DOCUMENT_AGENT_HISTORY FOREIGN KEY (agent_history_id) REFERENCES agent_history (id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        // --- decision_log ------------------------------------------------
        $this->addSql('CREATE TABLE decision_log (id SERIAL NOT NULL, decision TEXT NOT NULL, decision_type VARCHAR(50) DEFAULT NULL, description TEXT DEFAULT NULL, context JSON DEFAULT NULL, options JSON DEFAULT NULL, metadata JSON DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, approved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, approved_by VARCHAR(255) DEFAULT NULL, status VARCHAR(50) DEFAULT NULL, user_id INT NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_DECISION_LOG_USER ON decision_log (user_id)');
        $this->addSql('ALTER TABLE decision_log ADD CONSTRAINT FK_DECISION_LOG_USER FOREIGN KEY (user_id) REFERENCES user_profile (id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        // --- tool_category ----------------------------------------------
        $this->addSql('CREATE TABLE tool_category (id SERIAL NOT NULL, name VARCHAR(255) NOT NULL, description TEXT DEFAULT NULL, PRIMARY KEY(id))');

        // --- tool_definitions -------------------------------------------
        // Nach tool_category anlegen, da FK auf tool_category gesetzt wird.
        $this->addSql('CREATE TABLE tool_definitions (id SERIAL NOT NULL, name VARCHAR(255) NOT NULL, description TEXT NOT NULL, schema JSON NOT NULL, category_id INT DEFAULT NULL, complexity INT DEFAULT 1, dependencies JSON DEFAULT NULL, security_level VARCHAR(50) DEFAULT \'low\', requires_hitl BOOLEAN DEFAULT false, metadata JSON DEFAULT NULL, status VARCHAR(50) DEFAULT \'pending\', created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, executor_type VARCHAR(50) DEFAULT NULL, executor_config JSON DEFAULT NULL, security_policy JSON DEFAULT NULL, hitl_policy JSON DEFAULT NULL, version VARCHAR(50) DEFAULT \'1.0\', user_identifier VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_TOOL_DEFINITIONS_CATEGORY ON tool_definitions (category_id)');
        $this->addSql('CREATE INDEX idx_tool_definitions_user_identifier ON tool_definitions (user_identifier)');
        $this->addSql('ALTER TABLE tool_definitions ADD CONSTRAINT FK_TOOL_DEFINITIONS_CATEGORY FOREIGN KEY (category_id) REFERENCES tool_category (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');

        // --- audit_logs --------------------------------------------------
        $this->addSql('CREATE TABLE audit_logs (id SERIAL NOT NULL, action VARCHAR(100) NOT NULL, entity_type VARCHAR(255) DEFAULT NULL, entity_id INT DEFAULT NULL, user_id INT DEFAULT NULL, details TEXT DEFAULT NULL, context JSON DEFAULT NULL, ip_address VARCHAR(50) DEFAULT NULL, user_agent VARCHAR(500) DEFAULT NULL, status VARCHAR(50) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_AUDIT_USER ON audit_logs (user_id)');
        $this->addSql('CREATE INDEX IDX_AUDIT_ACTION ON audit_logs (action)');
        $this->addSql('CREATE INDEX IDX_AUDIT_CREATED ON audit_logs (created_at)');

        // --- embeddings (RAG) -------------------------------------------
        // vector-Spalte als FLOAT[] (entspricht aktuellem Entity-Mapping);
        // P1-A wird dies auf echten pgvector-Typ umstellen.
        $this->addSql('CREATE TABLE embeddings (id SERIAL NOT NULL, content_hash VARCHAR(255) NOT NULL, content TEXT NOT NULL, content_type VARCHAR(100) NOT NULL, source VARCHAR(255) DEFAULT NULL, metadata JSON NOT NULL, vector FLOAT[] NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_embedding_content_hash ON embeddings (content_hash)');
        $this->addSql('CREATE INDEX idx_embedding_content_type ON embeddings (content_type)');
        $this->addSql('CREATE INDEX idx_embedding_source ON embeddings (source)');
        // Hinweis: ein GIN-Index mit pgvector-Operator-Klasse (vector_l2_ops)
        // ist nur fuer echte pgvector vector-Spalten gueltig, nicht fuer
        // FLOAT[]. Die vector-Spalte wird in P1-A auf den pgvector-Typ
        // migriert; dann wird hier ein echter Aehnlichkeits-Index angelegt.

        // --- reset_password_request -------------------------------------
        // Nach users anlegen, da FK auf users gesetzt wird.
        $this->addSql('CREATE TABLE reset_password_request (id SERIAL NOT NULL, user_id INT NOT NULL, selector VARCHAR(100) NOT NULL, hashed_token VARCHAR(100) NOT NULL, requested_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_reset_password_request_selector ON reset_password_request (selector)');
        $this->addSql('CREATE INDEX idx_reset_password_request_expires_at ON reset_password_request (expires_at)');
        $this->addSql('CREATE INDEX idx_reset_password_request_user_id ON reset_password_request (user_id)');
        $this->addSql('ALTER TABLE reset_password_request ADD CONSTRAINT fk_reset_password_request_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        // --- ai_sub_agent_definitions (UUID-PK) -------------------------
        // Nach users anlegen, da FK created_by_id -> users.
        $this->addSql('CREATE TABLE ai_sub_agent_definitions (id UUID NOT NULL, name VARCHAR(255) NOT NULL, description TEXT NOT NULL, class_name VARCHAR(255) NOT NULL, configuration JSON NOT NULL, is_active BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_by_id INT DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_sub_agent_name ON ai_sub_agent_definitions (name)');
        $this->addSql('CREATE INDEX idx_sub_agent_active ON ai_sub_agent_definitions (is_active)');
        $this->addSql('CREATE INDEX idx_sub_agent_created_by ON ai_sub_agent_definitions (created_by_id)');
        $this->addSql('ALTER TABLE ai_sub_agent_definitions ADD CONSTRAINT fk_sub_agent_created_by FOREIGN KEY (created_by_id) REFERENCES users (id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        // --- ai_mcp_server_definitions (UUID-PK) -----------------------
        // Nach users anlegen, da FK created_by_id -> users.
        $this->addSql('CREATE TABLE ai_mcp_server_definitions (id UUID NOT NULL, name VARCHAR(255) NOT NULL, type VARCHAR(255) NOT NULL, description TEXT NOT NULL, configuration JSON NOT NULL, is_active BOOLEAN NOT NULL, allowed_tools JSON NOT NULL, blocked_resources JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_by_id INT DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_mcp_server_name ON ai_mcp_server_definitions (name)');
        $this->addSql('CREATE INDEX idx_mcp_server_type ON ai_mcp_server_definitions (type)');
        $this->addSql('CREATE INDEX idx_mcp_server_active ON ai_mcp_server_definitions (is_active)');
        $this->addSql('CREATE INDEX idx_mcp_server_created_by ON ai_mcp_server_definitions (created_by_id)');
        $this->addSql('ALTER TABLE ai_mcp_server_definitions ADD CONSTRAINT fk_mcp_server_created_by FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');

        // --- ai_streaming_sessions (UUID-PK) --------------------------
        // Nach users anlegen, da FK user_id -> users.
        $this->addSql('CREATE TABLE ai_streaming_sessions (id UUID NOT NULL, session_id VARCHAR(255) NOT NULL, tool_name VARCHAR(255) NOT NULL, initial_arguments JSON NOT NULL, user_identifier VARCHAR(255) NOT NULL, status VARCHAR(50) NOT NULL, current_progress TEXT DEFAULT NULL, progress_percentage DOUBLE PRECISION DEFAULT NULL, partial_results JSON DEFAULT NULL, final_result JSON DEFAULT NULL, error_data JSON DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, correlation_id VARCHAR(255) DEFAULT NULL, user_id INT DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_streaming_session_id ON ai_streaming_sessions (session_id)');
        $this->addSql('CREATE INDEX idx_streaming_session_status ON ai_streaming_sessions (status)');
        $this->addSql('CREATE INDEX idx_streaming_session_user ON ai_streaming_sessions (user_identifier)');
        $this->addSql('CREATE INDEX idx_streaming_session_tool ON ai_streaming_sessions (tool_name)');
        $this->addSql('CREATE INDEX idx_streaming_session_created ON ai_streaming_sessions (created_at)');
        $this->addSql('CREATE INDEX idx_streaming_session_user_id ON ai_streaming_sessions (user_id)');
        $this->addSql('ALTER TABLE ai_streaming_sessions ADD CONSTRAINT fk_streaming_session_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // Reihenfolge beachtet FK-Abhaengigkeiten (Kind zuerst).
        $this->addSql('ALTER TABLE ai_streaming_sessions DROP CONSTRAINT IF EXISTS fk_streaming_session_user');
        $this->addSql('DROP TABLE IF EXISTS ai_streaming_sessions');

        $this->addSql('ALTER TABLE ai_mcp_server_definitions DROP CONSTRAINT IF EXISTS fk_mcp_server_created_by');
        $this->addSql('DROP TABLE IF EXISTS ai_mcp_server_definitions');

        $this->addSql('ALTER TABLE ai_sub_agent_definitions DROP CONSTRAINT IF EXISTS fk_sub_agent_created_by');
        $this->addSql('DROP TABLE IF EXISTS ai_sub_agent_definitions');

        $this->addSql('ALTER TABLE reset_password_request DROP CONSTRAINT IF EXISTS fk_reset_password_request_user_id');
        $this->addSql('DROP TABLE IF EXISTS reset_password_request');

        $this->addSql('DROP TABLE IF EXISTS embeddings');
        $this->addSql('DROP TABLE IF EXISTS audit_logs');

        $this->addSql('ALTER TABLE tool_definitions DROP CONSTRAINT IF EXISTS FK_TOOL_DEFINITIONS_CATEGORY');
        $this->addSql('DROP TABLE IF EXISTS tool_definitions');
        $this->addSql('DROP TABLE IF EXISTS tool_category');

        $this->addSql('ALTER TABLE decision_log DROP CONSTRAINT IF EXISTS FK_DECISION_LOG_USER');
        $this->addSql('DROP TABLE IF EXISTS decision_log');

        $this->addSql('ALTER TABLE document DROP CONSTRAINT IF EXISTS FK_DOCUMENT_USER');
        $this->addSql('ALTER TABLE document DROP CONSTRAINT IF EXISTS FK_DOCUMENT_AGENT_HISTORY');
        $this->addSql('DROP TABLE IF EXISTS document');

        $this->addSql('ALTER TABLE agent_history DROP CONSTRAINT IF EXISTS FK_AGENT_HISTORY_USER');
        $this->addSql('ALTER TABLE agent_history DROP CONSTRAINT IF EXISTS FK_AGENT_HISTORY_SUB_AGENT');
        $this->addSql('DROP TABLE IF EXISTS agent_history');

        $this->addSql('ALTER TABLE sub_agent DROP CONSTRAINT IF EXISTS FK_SUB_AGENT_USER');
        $this->addSql('DROP TABLE IF EXISTS sub_agent');

        $this->addSql('ALTER TABLE user_profile DROP CONSTRAINT IF EXISTS FK_USER_PROFILE_USER');
        $this->addSql('DROP TABLE IF EXISTS user_profile');

        $this->addSql('DROP TABLE IF EXISTS users');
    }
}
