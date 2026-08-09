<?php

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration für die neuen Felder in ToolDefinition: approvedAt und rejectedAt.
 * Diese Felder werden für das Human-in-the-Loop (HITL) Tool-Freigabe-System benötigt.
 */
final class Version20260809082545 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds approved_at and rejected_at columns to tool_definitions table for HITL tracking';
    }

    public function up(Schema $schema): void
    {
        // Dies ist eine leere Migration, da die Felder bereits in der Entity definiert sind
        // und Doctrine die Migration automatisch generieren wird.
        // Diese Datei dient nur als Platzhalter und Dokumentation.
        
        // Die tatsächliche Migration wird von Doctrine automatisch generiert mit:
        // php bin/console doctrine:migrations:diff
        
        // Die erwarteten Änderungen:
        // - ALTER TABLE tool_definitions ADD approved_at TIMESTAMP NULL
        // - ALTER TABLE tool_definitions ADD rejected_at TIMESTAMP NULL
    }

    public function down(Schema $schema): void
    {
        // Dies ist eine leere Migration
    }
}