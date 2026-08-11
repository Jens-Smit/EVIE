<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260811063100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Document and SubAgent entities with relationships to UserProfile and AgentHistory';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE document (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, content LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, file_path VARCHAR(255) DEFAULT NULL, user_profile_id INT NOT NULL, agent_history_id INT DEFAULT NULL, INDEX IDX_DOCUMENT_USER_PROFILE (user_profile_id), INDEX IDX_DOCUMENT_AGENT_HISTORY (agent_history_id), CONSTRAINT FK_DOCUMENT_USER_PROFILE FOREIGN KEY (user_profile_id) REFERENCES user_profile (id) ON DELETE CASCADE, CONSTRAINT FK_DOCUMENT_AGENT_HISTORY FOREIGN KEY (agent_history_id) REFERENCES agent_history (id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        
        $this->addSql('CREATE TABLE sub_agent (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, description LONGTEXT NOT NULL, created_at DATETIME NOT NULL, capabilities JSON DEFAULT NULL, status VARCHAR(50) DEFAULT \'active\', user_profile_id INT NOT NULL, INDEX IDX_SUB_AGENT_USER_PROFILE (user_profile_id), CONSTRAINT FK_SUB_AGENT_USER_PROFILE FOREIGN KEY (user_profile_id) REFERENCES user_profile (id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        
        $this->addSql('ALTER TABLE agent_history ADD sub_agent_id INT DEFAULT NULL, ADD INDEX IDX_AGENT_HISTORY_SUB_AGENT (sub_agent_id), ADD CONSTRAINT FK_AGENT_HISTORY_SUB_AGENT FOREIGN KEY (sub_agent_id) REFERENCES sub_agent (id) ON DELETE SET NULL');
        
        $this->addSql('ALTER TABLE tool_definition ADD category_id INT NOT NULL, ADD INDEX IDX_TOOL_DEFINITION_CATEGORY (category_id), ADD CONSTRAINT FK_TOOL_DEFINITION_CATEGORY FOREIGN KEY (category_id) REFERENCES tool_category (id) ON DELETE CASCADE');
        
        $this->addSql('ALTER TABLE user_profile ADD documents_json JSON DEFAULT NULL, ADD sub_agents_json JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_profile DROP COLUMN documents_json, DROP COLUMN sub_agents_json');
        $this->addSql('ALTER TABLE tool_definition DROP FOREIGN KEY FK_TOOL_DEFINITION_CATEGORY, DROP COLUMN category_id');
        $this->addSql('ALTER TABLE agent_history DROP FOREIGN KEY FK_AGENT_HISTORY_SUB_AGENT, DROP COLUMN sub_agent_id');
        $this->addSql('DROP TABLE sub_agent');
        $this->addSql('DROP TABLE document');
    }
}
