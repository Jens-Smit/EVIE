<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Erstellt die tenant_quotas-Tabelle und fügt token_usage zu agent_history hinzu (Issue #16).
 */
final class Version20260904000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Erstellt die tenant_quotas-Tabelle und fügt token_usage zu agent_history hinzu (Issue #16).';
    }

    public function up(Schema $schema): void
    {
        // Erstellt tenant_quotas-Tabelle
        $this->addSql('CREATE TABLE tenant_quotas (id SERIAL PRIMARY KEY, user_identifier VARCHAR(255) NOT NULL, max_tokens_per_day INTEGER DEFAULT 100000 NOT NULL, max_requests_per_hour INTEGER DEFAULT 1000 NOT NULL, max_concurrent_requests INTEGER DEFAULT 100 NOT NULL, is_custom BOOLEAN DEFAULT false NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, current_day_usage INTEGER DEFAULT 0 NOT NULL, current_hour_usage INTEGER DEFAULT 0 NOT NULL, last_reset_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, user_profile_id INTEGER DEFAULT NULL)');
        $this->addSql('CREATE UNIQUE INDEX uniq_tenant_quota_user ON tenant_quotas (user_identifier)');
        $this->addSql('CREATE INDEX idx_tenant_quota_user ON tenant_quotas (user_identifier)');
        $this->addSql('ALTER TABLE tenant_quotas ADD CONSTRAINT FK_TENANT_QUOTAS_USER_PROFILE FOREIGN KEY (user_profile_id) REFERENCES user_profile (id) ON DELETE CASCADE');

        // Fügt token_usage zu agent_history hinzu
        $this->addSql('ALTER TABLE agent_history ADD token_usage INTEGER DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE tenant_quotas');
        $this->addSql('ALTER TABLE agent_history DROP COLUMN token_usage');
    }
}
