<?php
// src/Repository/McpServerDefinitionRepository.php

namespace App\Repository;

use App\Entity\McpServerDefinition;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository für MCP-Server-Definitionen.
 * Bietet Methoden zum Laden und Verwalten von MCP-Server-Definitionen aus der Datenbank.
 */
class McpServerDefinitionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, McpServerDefinition::class);
    }

    /**
     * Finde alle aktiven MCP-Server-Definitionen.
     * @return McpServerDefinition[]
     */
    public function findAllActive(): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.isActive = :isActive')
            ->setParameter('isActive', true)
            ->orderBy('m.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Finde eine MCP-Server-Definition nach Name.
     */
    public function findOneByName(string $name): ?McpServerDefinition
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.name = :name')
            ->setParameter('name', $name)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Finde MCP-Server nach Typ.
     * @return McpServerDefinition[]
     */
    public function findByType(string $type): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.type = :type')
            ->andWhere('m.isActive = :isActive')
            ->setParameter('type', $type)
            ->setParameter('isActive', true)
            ->orderBy('m.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Finde MCP-Server nach Typ und Name (für eindeutige Identifizierung).
     */
    public function findOneByTypeAndName(string $type, string $name): ?McpServerDefinition
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.type = :type')
            ->andWhere('m.name = :name')
            ->setParameter('type', $type)
            ->setParameter('name', $name)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Prüfe, ob ein MCP-Server mit dem gegebenen Namen existiert.
     */
    public function existsByName(string $name): bool
    {
        return $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->andWhere('m.name = :name')
            ->setParameter('name', $name)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * Finde alle MCP-Server, die ein bestimmtes Tool erlauben.
     * @return McpServerDefinition[]
     */
    public function findByAllowedTool(string $toolName): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.isActive = :isActive')
            ->andWhere(':toolName MEMBER OF m.allowedTools')
            ->setParameter('isActive', true)
            ->setParameter('toolName', $toolName)
            ->getQuery()
            ->getResult();
    }

    /**
     * Finde alle MCP-Server, die eine bestimmte Ressource blockieren.
     * @return McpServerDefinition[]
     */
    public function findByBlockedResource(string $resource): array
    {
        // Diese Abfrage ist komplexer, da wir JSON-Arrays durchsuchen müssen
        // In einer echten Implementierung würde man hier eine native SQL-Abfrage verwenden
        // oder die Filterung in PHP durchführen
        
        $allServers = $this->findAllActive();
        $result = [];
        
        foreach ($allServers as $server) {
            if ($server->isResourceBlocked($resource)) {
                $result[] = $server;
            }
        }
        
        return $result;
    }
}
