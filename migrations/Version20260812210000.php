<?php
// migrations/Version20260812210000.php

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260812210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates the table for Sub-Agent definitions.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ai_sub_agent_definitions ('
            . 'id BINARY(16) NOT NULL COMMENT "(DC2Type:uuid)",'
            . 'name VARCHAR(255) NOT NULL,'
            . 'description TEXT NOT NULL,'
            . 'class_name VARCHAR(255) NOT NULL,'
            . 'configuration JSON NOT NULL,'
            . 'is_active TINYINT(1) NOT NULL DEFAULT 1,'
            . 'created_at DATETIME NOT NULL,'
            . 'updated_at DATETIME DEFAULT NULL,'
            . 'created_by_id BINARY(16) DEFAULT NULL COMMENT "(DC2Type:uuid)",'
            . 'PRIMARY KEY (id),'
            . 'UNIQUE INDEX UNIQ_SUB_AGENT_NAME (name),'
            . 'INDEX IDX_SUB_AGENT_ACTIVE (is_active),'
            . 'INDEX IDX_SUB_AGENT_CREATED_BY (created_by_id)'
            . ')');

        $this->addSql('ALTER TABLE ai_sub_agent_definitions '
            . 'ADD CONSTRAINT FK_SUB_AGENT_CREATED_BY '
            . 'FOREIGN KEY (created_by_id) REFERENCES user (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ai_sub_agent_definitions');
    }
}
