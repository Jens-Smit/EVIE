<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration for EVIE Phase 3: LLM System
 * Creates table for LLMConfiguration with secret references.
 */
final class Version20260820103630 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates llm_configuration table for LLM provider configurations';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE llm_configuration (id VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, provider VARCHAR(50) NOT NULL, model VARCHAR(255) NOT NULL, endpoint VARCHAR(500) DEFAULT NULL, temperature DOUBLE PRECISION DEFAULT NULL, max_tokens BIGINT DEFAULT NULL, secret_reference VARCHAR(255) DEFAULT NULL, is_default BOOLEAN DEFAULT true, is_fallback BOOLEAN DEFAULT false, priority INT DEFAULT 0, created_at TIMESTAMPTZ NOT NULL, updated_at TIMESTAMPTZ NOT NULL, configuration JSON DEFAULT NULL, metadata JSON DEFAULT NULL, user_id VARCHAR(255) DEFAULT NULL, organization_id VARCHAR(255) DEFAULT NULL, tenant_id VARCHAR(255) NOT NULL, fallback_configuration_id VARCHAR(50) DEFAULT NULL, PRIMARY KEY(id))');
        
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D6495E237E06769B012B ON llm_configuration (name, user_id, organization_id)');
        $this->addSql('CREATE INDEX IDX_8D93D649A76ED395 ON llm_configuration (user_id)');
        $this->addSql('CREATE INDEX IDX_8D93D6493DA5256F ON llm_configuration (organization_id)');
        $this->addSql('CREATE INDEX IDX_8D93D649D87F330 ON llm_configuration (tenant_id)');
        $this->addSql('CREATE INDEX IDX_8D93D64977184430 ON llm_configuration (secret_reference)');
        
        $this->addSql('COMMENT ON COLUMN llm_configuration.configuration IS \(DC2Type:json\)');
        $this->addSql('COMMENT ON COLUMN llm_configuration.metadata IS \(DC2Type:json\)');

        // Add foreign key constraints
        $this->addSql('ALTER TABLE llm_configuration ADD CONSTRAINT FK_8D93D649A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE llm_configuration ADD CONSTRAINT FK_8D93D6493DA5256F FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE llm_configuration ADD CONSTRAINT FK_8D93D649D87F330 FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE llm_configuration DROP CONSTRAINT FK_8D93D649D87F330');
        $this->addSql('ALTER TABLE llm_configuration DROP CONSTRAINT FK_8D93D6493DA5256F');
        $this->addSql('ALTER TABLE llm_configuration DROP CONSTRAINT FK_8D93D649A76ED395');
        $this->addSql('DROP TABLE llm_configuration');
    }
}
