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
 * TABLE angelegt wurden. Zusaetzlich enthielten aeltere Migrationen
 * MySQL-ismen (TINYINT(1)) und fehlerhafte FK-Referenzen (REFERENCES user
 * statt users).
 *
 * Diese Migration konsolidiert das gesamte Schema in einem Schritt und ersetzt
 * die alte inkrementelle Kette. Die Index-Namen, DC2Type-Kommentare und
 * Typ-Assertionen entsprechen exakt dem, was doctrine:schema:validate erwartet
 * (verifiziert via doctrine:schema:update --dump-sql in CI).
 *
 * Alle folgenden Migrationen (Version20260811190100+) waren Transformationen
 * auf diesem Schema und sind durch diese Baseline obsolet geworden; sie
 * wurden nach migrations_archived/ verschoben.
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
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1483A5E9E7927C74 ON users (email)');

        // --- user_profile ------------------------------------------------
        $this->addSql('CREATE TABLE user_profile (id SERIAL NOT NULL, name VARCHAR(255) DEFAULT NULL, user_identifier VARCHAR(255) NOT NULL, email VARCHAR(255) DEFAULT NULL, preferences JSON DEFAULT NULL, user_type VARCHAR(255) DEFAULT NULL, context_embedding TEXT DEFAULT NULL, onboarding_data JSON DEFAULT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, user_id INT DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D95AB405D0494586 ON user_profile (user_identifier)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D95AB405A76ED395 ON user_profile (user_id)');
        $this->addSql('ALTER TABLE user_profile ADD CONSTRAINT FK_D95AB405A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        // --- sub_agent ---------------------------------------------------
        $this->addSql('CREATE TABLE sub_agent (id SERIAL NOT NULL, name VARCHAR(255) NOT NULL, description TEXT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id INT NOT NULL, capabilities JSON DEFAULT NULL, status VARCHAR(50) DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_CACFF824A76ED395 ON sub_agent (user_id)');
        $this->addSql('ALTER TABLE sub_agent ADD CONSTRAINT FK_CACFF824A76ED395 FOREIGN KEY (user_id) REFERENCES user_profile (id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        // --- agent_history ----------------------------------------------
        $this->addSql('CREATE TABLE agent_history (id SERIAL NOT NULL, action TEXT NOT NULL, details TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id INT NOT NULL, sub_agent_id INT DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_F5E912E1A76ED395 ON agent_history (user_id)');
        $this->addSql('CREATE INDEX IDX_F5E912E18EBB9F52 ON agent_history (sub_agent_id)');
        $this->addSql('ALTER TABLE agent_history ADD CONSTRAINT FK_F5E912E1A76ED395 FOREIGN KEY (user_id) REFERENCES user_profile (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE agent_history ADD CONSTRAINT FK_F5E912E18EBB9F52 FOREIGN KEY (sub_agent_id) REFERENCES sub_agent (id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        // --- document ----------------------------------------------------
        $this->addSql('CREATE TABLE document (id SERIAL NOT NULL, name VARCHAR(255) NOT NULL, content TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id INT NOT NULL, agent_history_id INT DEFAULT NULL, file_path VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_D8698A76A76ED395 ON document (user_id)');
        $this->addSql('CREATE INDEX IDX_D8698A766F43555D ON document (agent_history_id)');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A76A76ED395 FOREIGN KEY (user_id) REFERENCES user_profile (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A766F43555D FOREIGN KEY (agent_history_id) REFERENCES agent_history (id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        // --- decision_log ------------------------------------------------
        $this->addSql('CREATE TABLE decision_log (id SERIAL NOT NULL, decision TEXT NOT NULL, decision_type VARCHAR(50) DEFAULT NULL, description TEXT DEFAULT NULL, context JSON DEFAULT NULL, options JSON DEFAULT NULL, metadata JSON DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, approved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, approved_by VARCHAR(255) DEFAULT NULL, status VARCHAR(50) DEFAULT NULL, user_id INT NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_BB53662BA76ED395 ON decision_log (user_id)');
        $this->addSql('ALTER TABLE decision_log ADD CONSTRAINT FK_BB53662BA76ED395 FOREIGN KEY (user_id) REFERENCES user_profile (id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        // --- tool_category ----------------------------------------------
        $this->addSql('CREATE TABLE tool_category (id SERIAL NOT NULL, name VARCHAR(255) NOT NULL, description TEXT DEFAULT NULL, PRIMARY KEY(id))');

        // --- tool_definitions -------------------------------------------
        $this->addSql('CREATE TABLE tool_definitions (id SERIAL NOT NULL, name VARCHAR(255) NOT NULL, description TEXT NOT NULL, schema JSON NOT NULL, category_id INT DEFAULT NULL, complexity INT DEFAULT 1 NOT NULL, dependencies JSON DEFAULT NULL, security_level VARCHAR(50) DEFAULT \'low\' NOT NULL, requires_hitl BOOLEAN DEFAULT false NOT NULL, metadata JSON DEFAULT NULL, status VARCHAR(50) DEFAULT \'pending\' NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, executor_type VARCHAR(50) DEFAULT NULL, executor_config JSON DEFAULT NULL, security_policy JSON DEFAULT NULL, hitl_policy JSON DEFAULT NULL, version VARCHAR(50) DEFAULT NULL, user_identifier VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_95CBB9C12469DE2 ON tool_definitions (category_id)');
        $this->addSql('ALTER TABLE tool_definitions ADD CONSTRAINT FK_95CBB9C12469DE2 FOREIGN KEY (category_id) REFERENCES tool_category (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');

        // --- audit_logs --------------------------------------------------
        $this->addSql('CREATE TABLE audit_logs (id SERIAL NOT NULL, action VARCHAR(100) NOT NULL, entity_type VARCHAR(255) DEFAULT NULL, entity_id INT DEFAULT NULL, user_id INT DEFAULT NULL, details TEXT DEFAULT NULL, context JSON DEFAULT NULL, ip_address VARCHAR(50) DEFAULT NULL, user_agent VARCHAR(500) DEFAULT NULL, status VARCHAR(50) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');

        // --- embeddings (RAG) -------------------------------------------
        // vector-Spalte als JSON (entspricht Entity-Mapping Types::JSON).
        // P1-A migriert die Sparte spaeter auf echten pgvector-Typ.
        $this->addSql('CREATE TABLE embeddings (id SERIAL NOT NULL, content_hash VARCHAR(255) NOT NULL, content TEXT NOT NULL, content_type VARCHAR(100) NOT NULL, source VARCHAR(255) DEFAULT NULL, metadata JSON NOT NULL, vector JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');

        // --- reset_password_request -------------------------------------
        $this->addSql('CREATE TABLE reset_password_request (id SERIAL NOT NULL, user_id INT NOT NULL, selector VARCHAR(100) NOT NULL, hashed_token VARCHAR(100) NOT NULL, requested_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_7CE748A9692E25D ON reset_password_request (selector)');
        $this->addSql('CREATE INDEX IDX_7CE748AA76ED395 ON reset_password_request (user_id)');
        $this->addSql('CREATE INDEX IDX_7CE748AF9D83E2 ON reset_password_request (expires_at)');
        $this->addSql('ALTER TABLE reset_password_request ADD CONSTRAINT FK_7CE748AA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        // --- ai_sub_agent_definitions (UUID-PK) -------------------------
        $this->addSql('CREATE TABLE ai_sub_agent_definitions (id UUID NOT NULL, name VARCHAR(255) NOT NULL, description TEXT NOT NULL, class_name VARCHAR(255) NOT NULL, configuration JSON NOT NULL, is_active BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_by_id INT DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_D9950EB7B03A8386 ON ai_sub_agent_definitions (created_by_id)');
        $this->addSql('ALTER TABLE ai_sub_agent_definitions ADD CONSTRAINT FK_D9950EB7B03A8386 FOREIGN KEY (created_by_id) REFERENCES users (id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        // --- ai_mcp_server_definitions (UUID-PK) -----------------------
        $this->addSql('CREATE TABLE ai_mcp_server_definitions (id UUID NOT NULL, name VARCHAR(255) NOT NULL, type VARCHAR(255) NOT NULL, description TEXT NOT NULL, configuration JSON NOT NULL, is_active BOOLEAN NOT NULL, allowed_tools JSON NOT NULL, blocked_resources JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_by_id INT DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_78F351885E237E06 ON ai_mcp_server_definitions (name)');
        $this->addSql('CREATE INDEX IDX_78F35188B03A8386 ON ai_mcp_server_definitions (created_by_id)');
        $this->addSql('ALTER TABLE ai_mcp_server_definitions ADD CONSTRAINT FK_78F35188B03A8386 FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');

        // --- ai_streaming_sessions (UUID-PK) --------------------------
        $this->addSql('CREATE TABLE ai_streaming_sessions (id UUID NOT NULL, session_id VARCHAR(255) NOT NULL, tool_name VARCHAR(255) NOT NULL, initial_arguments JSON NOT NULL, user_identifier VARCHAR(255) NOT NULL, status VARCHAR(50) DEFAULT \'pending\' NOT NULL, current_progress TEXT DEFAULT NULL, progress_percentage DOUBLE PRECISION DEFAULT NULL, partial_results JSON DEFAULT NULL, final_result JSON DEFAULT NULL, error_data JSON DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, correlation_id VARCHAR(255) DEFAULT NULL, user_id INT DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_E47153B1613FECDF ON ai_streaming_sessions (session_id)');
        $this->addSql('CREATE INDEX IDX_E47153B1A76ED395 ON ai_streaming_sessions (user_id)');
        $this->addSql('ALTER TABLE ai_streaming_sessions ADD CONSTRAINT FK_E47153B1A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');

        // ------------------------------------------------------------------
        // Doctrine-Schema-Konventionen: SERIAL id DROP DEFAULT, UUID-Typen
        // und DC2Type-Kommentare, damit doctrine:schema:validate (In-Sync)
        // gruen wird. Entspricht doctrine:schema:update --dump-sql Output.
        // ------------------------------------------------------------------
        $this->addSql('ALTER TABLE users ALTER id DROP DEFAULT');
        $this->addSql('ALTER TABLE user_profile ALTER id DROP DEFAULT');
        $this->addSql('ALTER TABLE sub_agent ALTER id DROP DEFAULT');
        $this->addSql('ALTER TABLE agent_history ALTER id DROP DEFAULT');
        $this->addSql('ALTER TABLE document ALTER id DROP DEFAULT');
        $this->addSql('ALTER TABLE decision_log ALTER id DROP DEFAULT');
        $this->addSql('ALTER TABLE tool_category ALTER id DROP DEFAULT');
        $this->addSql('ALTER TABLE tool_definitions ALTER id DROP DEFAULT');
        $this->addSql('ALTER TABLE audit_logs ALTER id DROP DEFAULT');
        $this->addSql('ALTER TABLE embeddings ALTER id DROP DEFAULT');
        $this->addSql('ALTER TABLE reset_password_request ALTER id DROP DEFAULT');
        $this->addSql('ALTER TABLE ai_sub_agent_definitions ALTER id TYPE UUID');
        $this->addSql('ALTER TABLE ai_mcp_server_definitions ALTER id TYPE UUID');
        $this->addSql('ALTER TABLE ai_streaming_sessions ALTER id TYPE UUID');

        // DC2Type-Kommentare fuer datetime_immutable und uuid Spalten
        $this->addSql('COMMENT ON COLUMN users.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN users.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN users.last_login_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN user_profile.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN agent_history.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN sub_agent.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN document.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN decision_log.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN decision_log.approved_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN audit_logs.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN embeddings.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN reset_password_request.requested_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN reset_password_request.expires_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN tool_definitions.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN tool_definitions.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN ai_sub_agent_definitions.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN ai_sub_agent_definitions.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN ai_sub_agent_definitions.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN ai_mcp_server_definitions.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN ai_mcp_server_definitions.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN ai_mcp_server_definitions.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN ai_streaming_sessions.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN ai_streaming_sessions.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN ai_streaming_sessions.started_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN ai_streaming_sessions.completed_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN ai_streaming_sessions.updated_at IS \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        // Reihenfolge beachtet FK-Abhaengigkeiten (Kind zuerst).
        $this->addSql('ALTER TABLE ai_streaming_sessions DROP CONSTRAINT IF EXISTS FK_E47153B1A76ED395');
        $this->addSql('DROP TABLE IF EXISTS ai_streaming_sessions');

        $this->addSql('ALTER TABLE ai_mcp_server_definitions DROP CONSTRAINT IF EXISTS FK_78F35188B03A8386');
        $this->addSql('DROP TABLE IF EXISTS ai_mcp_server_definitions');

        $this->addSql('ALTER TABLE ai_sub_agent_definitions DROP CONSTRAINT IF EXISTS FK_D9950EB7B03A8386');
        $this->addSql('DROP TABLE IF EXISTS ai_sub_agent_definitions');

        $this->addSql('ALTER TABLE reset_password_request DROP CONSTRAINT IF EXISTS FK_7CE748AA76ED395');
        $this->addSql('DROP TABLE IF EXISTS reset_password_request');

        $this->addSql('DROP TABLE IF EXISTS embeddings');
        $this->addSql('DROP TABLE IF EXISTS audit_logs');

        $this->addSql('ALTER TABLE tool_definitions DROP CONSTRAINT IF EXISTS FK_95CBB9C12469DE2');
        $this->addSql('DROP TABLE IF EXISTS tool_definitions');
        $this->addSql('DROP TABLE IF EXISTS tool_category');

        $this->addSql('ALTER TABLE decision_log DROP CONSTRAINT IF EXISTS FK_BB53662BA76ED395');
        $this->addSql('DROP TABLE IF EXISTS decision_log');

        $this->addSql('ALTER TABLE document DROP CONSTRAINT IF EXISTS FK_D8698A76A76ED395');
        $this->addSql('ALTER TABLE document DROP CONSTRAINT IF EXISTS FK_D8698A766F43555D');
        $this->addSql('DROP TABLE IF EXISTS document');

        $this->addSql('ALTER TABLE agent_history DROP CONSTRAINT IF EXISTS FK_F5E912E1A76ED395');
        $this->addSql('ALTER TABLE agent_history DROP CONSTRAINT IF EXISTS FK_F5E912E18EBB9F52');
        $this->addSql('DROP TABLE IF EXISTS agent_history');

        $this->addSql('ALTER TABLE sub_agent DROP CONSTRAINT IF EXISTS FK_CACFF824A76ED395');
        $this->addSql('DROP TABLE IF EXISTS sub_agent');

        $this->addSql('ALTER TABLE user_profile DROP CONSTRAINT IF EXISTS FK_D95AB405A76ED395');
        $this->addSql('DROP TABLE IF EXISTS user_profile');

        $this->addSql('DROP TABLE IF EXISTS users');
    }
}
