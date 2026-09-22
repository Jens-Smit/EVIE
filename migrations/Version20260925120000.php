<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Frontend-Freigaben fuer ausgehende HTTP-Ziele (Blueprint HITL/Security):
 * Tabelle ai_outbound_allowlist persistiert Host-Freigaben, die per
 * Settings-UI (ROLE_ADMIN, CSRF, Audit-Log) erteilt und von der
 * OutboundRequestPolicy beruecksichtigt werden. Sobald aktive Eintraege
 * existieren, gelten nur noch freigegebene Hosts als erlaubt.
 */
final class Version20260925120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create ai_outbound_allowlist for frontend-managed outbound host approvals';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS ai_outbound_allowlist (id UUID NOT NULL, host_pattern VARCHAR(255) NOT NULL, pattern_type VARCHAR(16) NOT NULL, description TEXT DEFAULT NULL, is_active BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, organization_id VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_outbound_allowlist_host ON ai_outbound_allowlist (host_pattern)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_outbound_allowlist_organization ON ai_outbound_allowlist (organization_id)');
        $this->addSql('COMMENT ON COLUMN ai_outbound_allowlist.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN ai_outbound_allowlist.created_at IS \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS ai_outbound_allowlist');
    }
}
