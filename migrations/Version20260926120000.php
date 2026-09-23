<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P1 Fixplan: ai_sub_agent_definitions.class_name wird nullable und
 * bestehende Symfony\AI\Agent\Agent-Werte werden auf NULL bereinigt.
 *
 * Hintergrund (dev-tail-Log): SubAgentFactory::createFromDefinition() hat
 * bei class_name=Symfony\AI\Agent\Agent container->get($className)
 * aufgerufen, was mit "non-existent service" fehlschlug, weil die konkrete
 * Agent-Klasse keine Symfony-DI-Service-ID ist. class_name ist ausschliesslich
 * fuer echte, als Service registrierte AgentInterface-Implementierungen
 * gedacht; generische DB-Agenten laufen ueber die Konfiguration (model/role)
 * ohne class_name.
 */
final class Version20260926120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make ai_sub_agent_definitions.class_name nullable and drop non-service class names';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("DO \$\$ BEGIN IF to_regclass('ai_sub_agent_definitions') IS NOT NULL THEN ALTER TABLE ai_sub_agent_definitions ALTER COLUMN class_name DROP NOT NULL; UPDATE ai_sub_agent_definitions SET class_name = NULL WHERE class_name = 'Symfony\\AI\\Agent\\Agent'; END IF; END \$\$");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DO \$\$ BEGIN IF to_regclass('ai_sub_agent_definitions') IS NOT NULL THEN UPDATE ai_sub_agent_definitions SET class_name = 'Symfony\\AI\\Agent\\Agent' WHERE class_name IS NULL; ALTER TABLE ai_sub_agent_definitions ALTER COLUMN class_name SET NOT NULL; END IF; END \$\$");
    }
}
