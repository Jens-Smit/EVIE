<?php

namespace App\Repository\Integration;

use App\Entity\Integration\Integration;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Integration>
 *
 * @method Integration|null find($id, $lockMode = null, $lockVersion = null)
 * @method Integration|null findOneBy(array $criteria, array $orderBy = null)
 * @method Integration[]    findAll()
 * @method Integration[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class IntegrationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Integration::class);
    }

    public function save(Integration $integration, bool $flush = false): void
    {
        $this->_em->persist($integration);
        if ($flush) {
            $this->_em->flush();
        }
    }

    public function remove(Integration $integration, bool $flush = false): void
    {
        $this->_em->remove($integration);
        if ($flush) {
            $this->_em->flush();
        }
    }

    /**
     * Find integrations by tenant ID.
     */
    public function findByTenant(string $tenantId): array
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.tenant = :tenantId')
            ->setParameter('tenantId', $tenantId)
            ->orderBy('i.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find integrations by type.
     */
    public function findByType(string $tenantId, string $type): array
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.tenant = :tenantId')
            ->andWhere('i.type = :type')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('type', $type)
            ->orderBy('i.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find enabled integrations by tenant ID.
     */
    public function findEnabledByTenant(string $tenantId): array
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.tenant = :tenantId')
            ->andWhere('i.isEnabled = :isEnabled')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('isEnabled', true)
            ->orderBy('i.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find connected integrations by tenant ID.
     */
    public function findConnectedByTenant(string $tenantId): array
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.tenant = :tenantId')
            ->andWhere('i.isConnected = :isConnected')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('isConnected', true)
            ->orderBy('i.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find ready integrations by tenant ID (enabled, configured, connected).
     */
    public function findReadyByTenant(string $tenantId): array
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.tenant = :tenantId')
            ->andWhere('i.isEnabled = :isEnabled')
            ->andWhere('i.isConfigured = :isConfigured')
            ->andWhere('i.isConnected = :isConnected')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('isEnabled', true)
            ->setParameter('isConfigured', true)
            ->setParameter('isConnected', true)
            ->orderBy('i.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find an integration by identifier and tenant ID.
     */
    public function findOneByIdentifierAndTenant(string $identifier, string $tenantId): ?Integration
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.identifier = :identifier')
            ->andWhere('i.tenant = :tenantId')
            ->setParameter('identifier', $identifier)
            ->setParameter('tenantId', $tenantId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find an integration by type and identifier and tenant ID.
     */
    public function findOneByTypeAndIdentifierAndTenant(
        string $type,
        string $identifier,
        string $tenantId
    ): ?Integration {
        return $this->createQueryBuilder('i')
            ->andWhere('i.type = :type')
            ->andWhere('i.identifier = :identifier')
            ->andWhere('i.tenant = :tenantId')
            ->setParameter('type', $type)
            ->setParameter('identifier', $identifier)
            ->setParameter('tenantId', $tenantId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find integrations by organization ID.
     */
    public function findByOrganization(string $organizationId): array
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.organization = :organizationId')
            ->setParameter('organizationId', $organizationId)
            ->orderBy('i.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find integrations that have a specific capability.
     */
    public function findByCapability(string $tenantId, string $capability): array
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.tenant = :tenantId')
            ->andWhere(':capability MEMBER OF i.capabilities')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('capability', $capability)
            ->orderBy('i.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Check if an integration exists for a tenant.
     */
    public function existsByIdentifierAndTenant(string $identifier, string $tenantId): bool
    {
        return $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->andWhere('i.identifier = :identifier')
            ->andWhere('i.tenant = :tenantId')
            ->setParameter('identifier', $identifier)
            ->setParameter('tenantId', $tenantId)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * Get integration count for a tenant.
     */
    public function countByTenant(string $tenantId): int
    {
        return $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->andWhere('i.tenant = :tenantId')
            ->setParameter('tenantId', $tenantId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Get integration count by type for a tenant.
     */
    public function countByType(string $tenantId): array
    {
        $conn = $this->_em->getConnection();
        
        $sql = 'SELECT type, COUNT(*) as count 
            FROM integration 
            WHERE tenant_id = :tenantId 
            GROUP BY type';
        
        $stmt = $conn->prepare($sql);
        $stmt->executeQuery(['tenantId' => $tenantId]);
        
        $results = $stmt->fetchAllAssociative();
        
        $counts = [];
        foreach ($results as $result) {
            $counts[$result['type']] = (int)$result['count'];
        }
        
        return $counts;
    }

    /**
     * Get integration statistics for a tenant.
     */
    public function getStatistics(string $tenantId): array
    {
        $conn = $this->_em->getConnection();
        
        $sql = 'SELECT 
            COUNT(*) as total,
            COUNT(CASE WHEN is_enabled = true THEN 1 END) as enabled,
            COUNT(CASE WHEN is_configured = true THEN 1 END) as configured,
            COUNT(CASE WHEN is_connected = true THEN 1 END) as connected,
            COUNT(CASE WHEN is_enabled = true AND is_configured = true AND is_connected = true THEN 1 END) as ready
            FROM integration 
            WHERE tenant_id = :tenantId';
        
        $stmt = $conn->prepare($sql);
        $result = $stmt->executeQuery(['tenantId' => $tenantId]);
        
        return $result->fetchAssociative();
    }

    /**
     * Search integrations by name or description.
     */
    public function search(string $tenantId, string $query): array
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.tenant = :tenantId')
            ->andWhere('i.name LIKE :query OR i.description LIKE :query OR i.identifier LIKE :query')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('query', '%' . $query . '%')
            ->orderBy('i.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find integrations that need configuration.
     */
    public function findUnconfigured(string $tenantId): array
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.tenant = :tenantId')
            ->andWhere('i.isEnabled = :isEnabled')
            ->andWhere('i.isConfigured = :isConfigured OR i.isConnected = :isConnected')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('isEnabled', true)
            ->setParameter('isConfigured', false)
            ->setParameter('isConnected', false)
            ->orderBy('i.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
