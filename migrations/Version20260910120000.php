<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MailDraft-Entity fuer den HITL-E-Mail-Freigabe-Workflow (Blueprint §5):
 * Agenten legen E-Mail-Entwuerfe als persistente mail_drafts an
 * (status=pending_approval); erst nach Freigabe wird versendet.
 */
final class Version20260910120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create mail_drafts table for HITL e-mail approval workflow';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE mail_drafts_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE mail_drafts (id INT NOT NULL, user_profile_id INT NOT NULL, sub_agent_id INT DEFAULT NULL, user_identifier VARCHAR(255) NOT NULL, subject VARCHAR(255) NOT NULL, body TEXT NOT NULL, recipients JSON NOT NULL, sender VARCHAR(255) DEFAULT NULL, attachments JSON NOT NULL, status VARCHAR(50) NOT NULL, metadata JSON DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, approved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, rejection_reason TEXT DEFAULT NULL, is_html BOOLEAN NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_mail_draft_status ON mail_drafts (status)');
        $this->addSql('CREATE INDEX IDX_31135BAB6B9DD454 ON mail_drafts (user_profile_id)');
        $this->addSql('CREATE INDEX IDX_31135BAB8EBB9F52 ON mail_drafts (sub_agent_id)');
        $this->addSql('COMMENT ON COLUMN mail_drafts.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN mail_drafts.approved_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN mail_drafts.sent_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE mail_drafts ADD CONSTRAINT FK_31135BAB6B9DD454 FOREIGN KEY (user_profile_id) REFERENCES user_profile (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE mail_drafts ADD CONSTRAINT FK_31135BAB8EBB9F52 FOREIGN KEY (sub_agent_id) REFERENCES sub_agent (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mail_drafts DROP CONSTRAINT FK_31135BAB6B9DD454');
        $this->addSql('ALTER TABLE mail_drafts DROP CONSTRAINT FK_31135BAB8EBB9F52');
        $this->addSql('DROP TABLE mail_drafts');
        $this->addSql('DROP SEQUENCE mail_drafts_id_seq CASCADE');
    }
}
