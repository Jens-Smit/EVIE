<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use DoctrineDBALSchemaSchema;
use DoctrineMigrationsAbstractMigration;

/**
 * Creates audit_logs table for Phase 3: Security & Audit Trail
 */
final class Version20260813180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates audit_logs table for Audit Trail';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE audit_logs (id SERIAL NOT NULL, action VARCHAR(100) NOT NULL, entity_type VARCHAR(255) DEFAULT NULL, entity_id INT DEFAULT NULL, user_id INT DEFAULT NULL, details TEXT DEFAULT NULL, context JSON DEFAULT NULL, ip_address VARCHAR(50) DEFAULT NULL, user_agent VARCHAR(500) DEFAULT NULL, status VARCHAR(50) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_AUDIT_USER ON audit_logs (user_id)');
        $this->addSql('CREATE INDEX IDX_AUDIT_ACTION ON audit_logs (action)');
        $this->addSql('CREATE INDEX IDX_AUDIT_CREATED ON audit_logs (created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE audit_logs');
    }
}
