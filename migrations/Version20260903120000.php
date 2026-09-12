<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add approved_at, rejected_at und rejection_reason to tool_definitions,
 * damit der HITL-Freigabe-Controller (ToolApprovalController) die
 * Freigabe-/Ablehnungs-Metadaten persistieren kann.
 */
final class Version20260903120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add approved_at, rejected_at, rejection_reason to tool_definitions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tool_definitions ADD approved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE tool_definitions ADD rejected_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE tool_definitions ADD rejection_reason VARCHAR(500) DEFAULT NULL');
        $this->addSql('COMMENT ON COLUMN tool_definitions.approved_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN tool_definitions.rejected_at IS \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tool_definitions DROP approved_at');
        $this->addSql('ALTER TABLE tool_definitions DROP rejected_at');
        $this->addSql('ALTER TABLE tool_definitions DROP rejection_reason');
    }
}
