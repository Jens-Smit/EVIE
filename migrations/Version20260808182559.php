<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260808182559 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE agent_history CHANGE action action JSON NOT NULL, CHANGE input input JSON DEFAULT NULL, CHANGE output output JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE tool_definitions CHANGE `schema` `schema` JSON NOT NULL, CHANGE parameters parameters JSON DEFAULT NULL, CHANGE updated_at updated_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE user_profiles CHANGE preferences preferences JSON NOT NULL, CHANGE updated_at updated_at DATETIME DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_USER_IDENTIFIER ON user_profiles (user_identifier)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE agent_history CHANGE action action LONGTEXT NOT NULL COLLATE `utf8mb4_bin`, CHANGE input input LONGTEXT DEFAULT NULL COLLATE `utf8mb4_bin`, CHANGE output output LONGTEXT DEFAULT NULL COLLATE `utf8mb4_bin`');
        $this->addSql('ALTER TABLE tool_definitions CHANGE `schema` `schema` LONGTEXT NOT NULL COLLATE `utf8mb4_bin`, CHANGE parameters parameters LONGTEXT DEFAULT NULL COLLATE `utf8mb4_bin`, CHANGE updated_at updated_at DATETIME DEFAULT \'NULL\'');
        $this->addSql('DROP INDEX UNIQ_USER_IDENTIFIER ON user_profiles');
        $this->addSql('ALTER TABLE user_profiles CHANGE preferences preferences LONGTEXT NOT NULL COLLATE `utf8mb4_bin`, CHANGE updated_at updated_at DATETIME DEFAULT \'NULL\'');
    }
}
