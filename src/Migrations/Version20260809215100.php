<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration für ToolCategory und DecisionLog Entitäten.
 * Fügt neue Tabellen für die erweiterte Tool-Verwaltung und Entscheidungsprotokollierung hinzu.
 */
final class Version20260809215100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Erstellt ToolCategory und DecisionLog Tabellen und erweitert ToolDefinition';
    }

    public function up(Schema $schema): void
    {
        // ToolCategory Tabelle erstellen
        $this->addSql('CREATE TABLE tool_categories (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, description VARCHAR(255) NOT NULL, color VARCHAR(50) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, UNIQUE INDEX UNIQ_1483A5E95E237E06 (name), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // DecisionLog Tabelle erstellen
        $this->addSql('CREATE TABLE decision_logs (id INT AUTO_INCREMENT NOT NULL, decision_type VARCHAR(100) NOT NULL, description LONGTEXT NOT NULL, context JSON DEFAULT NULL, options JSON DEFAULT NULL, status VARCHAR(50) NOT NULL, approved_by VARCHAR(255) DEFAULT NULL, approved_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, metadata JSON DEFAULT NULL, user_profile_id INT DEFAULT NULL, INDEX IDX_1483A5E9C33F6076 (user_profile_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // ToolDefinition Tabelle erweitern
        $this->addSql('ALTER TABLE tool_definitions ADD category_id INT DEFAULT NULL, ADD complexity VARCHAR(50) DEFAULT NULL, ADD dependencies JSON DEFAULT NULL, ADD metadata JSON DEFAULT NULL');
        
        // Fremdschlüssel für ToolDefinition -> ToolCategory
        $this->addSql('ALTER TABLE tool_definitions ADD CONSTRAINT FK_1483A5E9C33F6076 FOREIGN KEY (category_id) REFERENCES tool_categories (id)');
        
        // Fremdschlüssel für DecisionLog -> UserProfile
        $this->addSql('ALTER TABLE decision_logs ADD CONSTRAINT FK_DECISION_LOG_USER_PROFILE FOREIGN KEY (user_profile_id) REFERENCES user_profiles (id)');

        // Index für ToolDefinition Felder
        $this->addSql('CREATE INDEX IDX_TOOL_DEFINITION_CATEGORY ON tool_definitions (category_id)');
        $this->addSql('CREATE INDEX IDX_TOOL_DEFINITION_COMPLEXITY ON tool_definitions (complexity)');

        // Index für DecisionLog Felder
        $this->addSql('CREATE INDEX IDX_DECISION_LOG_TYPE ON decision_logs (decision_type)');
        $this->addSql('CREATE INDEX IDX_DECISION_LOG_STATUS ON decision_logs (status)');
        $this->addSql('CREATE INDEX IDX_DECISION_LOG_CREATED_AT ON decision_logs (created_at)');

        // Standard-Kategorien einfügen
        $this->addSql("INSERT INTO tool_categories (name, description, color, created_at) VALUES ('web_scraping', 'Webseiten durchsuchen, analysieren und Inhalte extrahieren', '#3b82f6', NOW())");
        $this->addSql("INSERT INTO tool_categories (name, description, color, created_at) VALUES ('data_analysis', 'Daten analysieren, Statistiken berechnen, Muster erkennen', '#10b981', NOW())");
        $this->addSql("INSERT INTO tool_categories (name, description, color, created_at) VALUES ('communication', 'E-Mails, Nachrichten, Social Media verwalten', '#8b5cf6', NOW())");
        $this->addSql("INSERT INTO tool_categories (name, description, color, created_at) VALUES ('api_integration', 'APIs anbinden, OAuth, REST, GraphQL', '#f59e0b', NOW())");
        $this->addSql("INSERT INTO tool_categories (name, description, color, created_at) VALUES ('document_processing', 'PDFs, Excel-Dateien, Dokumente verarbeiten', '#ef4444', NOW())");
        $this->addSql("INSERT INTO tool_categories (name, description, color, created_at) VALUES ('code_generation', 'Code generieren, analysieren, testen', '#16a34a', NOW())");
        $this->addSql("INSERT INTO tool_categories (name, description, color, created_at) VALUES ('project_management', 'Aufgaben, Termine, Projekte verwalten', '#06b6d4', NOW())");
        $this->addSql("INSERT INTO tool_categories (name, description, color, created_at) VALUES ('general', 'Allgemeine Tools und Funktionen', '#6b7280', NOW())");
    }

    public function down(Schema $schema): void
    {
        // Fremdschlüssel Constraints entfernen
        $this->addSql('ALTER TABLE tool_definitions DROP FOREIGN KEY FK_1483A5E9C33F6076');
        $this->addSql('ALTER TABLE decision_logs DROP FOREIGN KEY FK_DECISION_LOG_USER_PROFILE');

        // Indexes entfernen
        $this->addSql('DROP INDEX IDX_TOOL_DEFINITION_CATEGORY ON tool_definitions');
        $this->addSql('DROP INDEX IDX_TOOL_DEFINITION_COMPLEXITY ON tool_definitions');
        $this->addSql('DROP INDEX IDX_DECISION_LOG_TYPE ON decision_logs');
        $this->addSql('DROP INDEX IDX_DECISION_LOG_STATUS ON decision_logs');
        $this->addSql('DROP INDEX IDX_DECISION_LOG_CREATED_AT ON decision_logs');
        $this->addSql('DROP INDEX IDX_1483A5E9C33F6076 ON decision_logs');

        // Spalten aus ToolDefinition entfernen
        $this->addSql('ALTER TABLE tool_definitions DROP COLUMN category_id, DROP COLUMN complexity, DROP COLUMN dependencies, DROP COLUMN metadata');

        // Tabellen entfernen
        $this->addSql('DROP TABLE decision_logs');
        $this->addSql('DROP TABLE tool_categories');
    }
}
