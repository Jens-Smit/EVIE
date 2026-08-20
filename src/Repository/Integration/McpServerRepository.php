<?php

namespace App\Repository\Integration;

use App\Entity\Integration\McpServer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<McpServer>
 *
 * @method McpServer|null find($id, $lockMode = null, $lockVersion = null)
 * @method McpServer|null findOneBy(array $criteria, array $orderBy = null)
 * @method McpServer[]    findAll()
 * @method McpServer[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class McpServerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, McpServer::class);
    }

    public function save(McpServer $server, bool $flush = false): void
    {
        $this->_em->persist($server);
        if ($flush) {
            $this->_em->flush();
        }
    }

    public function remove(McpServer $server, bool $flush = false): void
    {
        $this->_em->remove($server);
        if ($flush) {
            $this->_em->flush();
        }
    }

    /**
     * Find MCP servers by tenant ID.
     */
    public function findByTenant(string $tenantId): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.tenant = :tenantId')
            ->setParameter('tenantId', $tenantId)
            ->orderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find enabled MCP servers by tenant ID.
     */
    public function findEnabledByTenant(string $tenantId): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.tenant = :tenantId')
            ->andWhere('s.isEnabled = :isEnabled')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('isEnabled', true)
            ->orderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find connected MCP servers by tenant ID.
     */
    public function findConnectedByTenant(string $tenantId): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.tenant = :tenantId')
            ->andWhere('s.isConnected = :isConnected')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('isConnected', true)
            ->orderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find ready MCP servers by tenant ID (enabled and connected).
     */
    public function findReadyByTenant(string $tenantId): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.tenant = :tenantId')
            ->andWhere('s.isEnabled = :isEnabled')
            ->andWhere('s.isConnected = :isConnected')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('isEnabled', true)
            ->setParameter('isConnected', true)
            ->orderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find an MCP server by identifier and tenant ID.
     */
    public function findOneByIdentifierAndTenant(string $identifier, string $tenantId): ?McpServer
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.identifier = :identifier')
            ->andWhere('s.tenant = :tenantId')
            ->setParameter('identifier', $identifier)
            ->setParameter('tenantId', $tenantId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Check if an MCP server exists for a tenant.
     */
    public function existsByIdentifierAndTenant(string $identifier, string $tenantId): bool
    {
        return $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.identifier = :identifier')
            ->andWhere('s.tenant = :tenantId')
            ->setParameter('identifier', $identifier)
            ->setParameter('tenantId', $tenantId)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * Get MCP server count for a tenant.
     */
    public function countByTenant(string $tenantId): int
    {
        return $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.tenant = :tenantId')
            ->setParameter('tenantId', $tenantId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Get MCP server statistics for a tenant.
     */
    public function getStatistics(string $tenantId): array
    {
        $conn = $this->_em->getConnection();
        
        $sql = 'SELECT 
            COUNT(*) as total,
            COUNT(CASE WHEN is_enabled = true THEN 1 END) as enabled,
            COUNT(CASE WHEN is_connected = true THEN 1 END) as connected,
            COUNT(CASE WHEN is_enabled = true AND is_connected = true THEN 1 END) as ready
            FROM mcp_server 
            WHERE tenant_id = :tenantId';
        
        $stmt = $conn->prepare($sql);
        $result = $stmt->executeQuery(['tenantId' => $tenantId]);
        
        return $result->fetchAssociative();
    }

    /**
     * Search MCP servers by name or description.
     */
    public function search(string $tenantId, string $query): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.tenant = :tenantId')
            ->andWhere('s.name LIKE :query OR s.description LIKE :query OR s.identifier LIKE :query')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('query', '%' . $query . '%')
            ->orderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find MCP servers by type.
     */
    public function findByType(string $tenantId, string $type): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.tenant = :tenantId')
            ->andWhere('s.type = :type')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('type', $type)
            ->orderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find MCP servers that have a specific tool.
     */
    public function findByTool(string $tenantId, string $toolName): array
    {
        // This is a bit tricky with Doctrine, we'll use native query
        $conn = $this->_em->getConnection();
        
        $sql = 'SELECT s.* 
            FROM mcp_server s 
            WHERE s.tenant_id = :tenantId 
            AND s.tools::jsonb @> :toolJson::jsonb';
        
        $stmt = $conn->prepare($sql);
        $stmt->executeQuery([
            'tenantId' => $tenantId,
            'toolJson' => json_encode([['name' => $toolName]]),
        ]);
        
        $results = $stmt->fetchAllAssociative();
        
        if (empty($results)) {
            return [];
        }

        // Convert to entities
        $servers = [];
        foreach ($results as $result) {
            $server = $this->find($result['id']);
            if ($server !== null) {
                $servers[] = $server;
            }
        }

        return $servers;
    }

    /**
     * Find MCP servers that have a specific resource.
     */
    public function findByResource(string $tenantId, string $resourceName): array
    {
        // This is a bit tricky with Doctrine, we'll use native query
        $conn = $this->_em->getConnection();
        
        $sql = 'SELECT s.* 
            FROM mcp_server s 
            WHERE s.tenant_id = :tenantId 
            AND s.resources::jsonb @> :resourceJson::jsonb';
        
        $stmt = $conn->prepare($sql);
        $stmt->executeQuery([
            'tenantId' => $tenantId,
            'resourceJson' => json_encode([['name' => $resourceName]]),
        ]);
        
        $results = $stmt->fetchAllAssociative();
        
        if (empty($results)) {
            return [];
        }

        // Convert to entities
        $servers = [];
        foreach ($results as $result) {
            $server = $this->find($result['id']);
            if ($server !== null) {
                $servers[] = $server;
            }
        }

        return $servers;
    }
}
