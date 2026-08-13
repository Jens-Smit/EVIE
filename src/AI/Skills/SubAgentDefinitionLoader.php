<?php

namespace AppAISkills;

use AppEntityToolDefinition;
use DoctrineORMEntityManagerInterface;

/**
 * Lädt Tool-Definitionen aus verschiedenen Quellen (DB, statisch, dynamisch)
 */
class SubAgentDefinitionLoader
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
    }

    /**
     * Lädt eine Tool-Definition aus der Datenbank
     */
    public function loadFromDatabase(int $id): ?ToolDefinition
    {
        return $this->entityManager->getRepository(ToolDefinition::class)->find($id);
    }

    /**
     * Lädt alle approved Tool-Definitionen
     */
    public function loadAllApproved(): array
    {
        return $this->entityManager->getRepository(ToolDefinition::class)
            ->findBy(['status' => 'approved']);
    }

    /**
     * Lädt alle pending Tool-Definitionen
     */
    public function loadAllPending(): array
    {
        return $this->entityManager->getRepository(ToolDefinition::class)
            ->findBy(['status' => 'pending']);
    }

    /**
     * Lädt statische Tool-Definitionen
     */
    public function loadStaticDefinitions(): array
    {
        // Statische Tools die immer verfügbar sind
        return [];
    }
}
