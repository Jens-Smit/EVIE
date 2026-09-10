<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create organization_users join table for the unidirectional
 * Organization#users association (ManyToMany with unique user_id).
 * Without an owning-side field on User, the OneToMany mapping was
 * invalid; a unidirectional ManyToMany via join table is the
 * Doctrine-compatible representation.
 */
final class Version20260903130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create organization_users join table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE organization_users (organization_id INT NOT NULL, user_id INT NOT NULL, PRIMARY KEY(organization_id, user_id))');
        $this->addSql('CREATE INDEX IDX_60B8650B32C8A3 ON organization_users (organization_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_60B8650BA76ED395 ON organization_users (user_id)');
        $this->addSql('ALTER TABLE organization_users ADD CONSTRAINT FK_60B8650B32C8A3 FOREIGN KEY (organization_id) REFERENCES organizations (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE organization_users ADD CONSTRAINT FK_60B8650A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE organization_users DROP CONSTRAINT FK_60B8650B32C8A3');
        $this->addSql('ALTER TABLE organization_users DROP CONSTRAINT FK_60B8650A76ED395');
        $this->addSql('DROP TABLE organization_users');
    }
}
