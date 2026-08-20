<?php

namespace App\Repository\AI;

use App\Entity\AI\Capability;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Capability>
 *
 * @method Capability|null find($id, $lockMode = null, $lockVersion = null)
 * @method Capability|null findOneBy(array $criteria, array $orderBy = null)
 * @method Capability[]    findAll()
 * @method Capability[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class CapabilityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Capability::class);
    }

    public function save(Capability $capability, bool $flush = false): void
    {
        $this->_em->persist($capability);
        if ($flush) {
            $this->_em->flush();
        }
    }

    public function remove(Capability $capability, bool $flush = false): void
    {
        $this->_em->remove($capability);
        if ($flush) {
            $this->_em->flush();
        }
    }

    /**
     * Find capabilities by tenant ID.
     */
    public function findByTenant(string $tenantId): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.tenant = :tenantId')
            ->setParameter('tenantId', $tenantId)
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find enabled capabilities by tenant ID.
     */
    public function findEnabledByTenant(string $tenantId): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.tenant = :tenantId')
            ->andWhere('c.isEnabled = :isEnabled')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('isEnabled', true)
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find installed capabilities by tenant ID.
     */
    public function findInstalledByTenant(string $tenantId): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.tenant = :tenantId')
            ->andWhere('c.isInstalled = :isInstalled')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('isInstalled', true)
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find ready capabilities by tenant ID (enabled, installed, configured).
     */
    public function findReadyByTenant(string $tenantId): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.tenant = :tenantId')
            ->andWhere('c.isEnabled = :isEnabled')
            ->andWhere('c.isInstalled = :isInstalled')
            ->andWhere('c.isConfigured = :isConfigured')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('isEnabled', true)
            ->setParameter('isInstalled', true)
            ->setParameter('isConfigured', true)
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find capabilities by organization ID.
     */
    public function findByOrganization(string $organizationId): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.organization = :organizationId')
            ->setParameter('organizationId', $organizationId)
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find capabilities by category.
     */
    public function findByCategory(string $tenantId, string $category): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.tenant = :tenantId')
            ->andWhere('c.category = :category')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('category', $category)
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find a capability by identifier and tenant ID.
     */
    public function findOneByIdentifierAndTenant(string $identifier, string $tenantId): ?Capability
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.identifier = :identifier')
            ->andWhere('c.tenant = :tenantId')
            ->setParameter('identifier', $identifier)
            ->setParameter('tenantId', $tenantId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find capabilities by identifier (across all tenants).
     */
    public function findByIdentifier(string $identifier): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.identifier = :identifier')
            ->setParameter('identifier', $identifier)
            ->orderBy('c.tenant', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find capabilities that require a specific secret.
     */
    public function findByRequiredSecret(string $tenantId, string $secretKey): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.tenant = :tenantId')
            ->andWhere(':secretKey MEMBER OF c.requiredSecrets')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('secretKey', $secretKey)
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find capabilities that require a specific integration.
     */
    public function findByRequiredIntegration(string $tenantId, string $integration): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.tenant = :tenantId')
            ->andWhere(':integration MEMBER OF c.requiredIntegrations')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('integration', $integration)
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find capabilities that require a specific permission.
     */
    public function findByRequiredPermission(string $tenantId, string $permission): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.tenant = :tenantId')
            ->andWhere(':permission MEMBER OF c.requiredPermissions')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('permission', $permission)
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Check if a capability exists for a tenant.
     */
    public function existsByIdentifierAndTenant(string $identifier, string $tenantId): bool
    {
        return $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.identifier = :identifier')
            ->andWhere('c.tenant = :tenantId')
            ->setParameter('identifier', $identifier)
            ->setParameter('tenantId', $tenantId)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * Get capability count for a tenant.
     */
    public function countByTenant(string $tenantId): int
    {
        return $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.tenant = :tenantId')
            ->setParameter('tenantId', $tenantId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Get capability count by category for a tenant.
     */
    public function countByCategory(string $tenantId): array
    {
        $conn = $this->_em->getConnection();
        
        $sql = 'SELECT category, COUNT(*) as count 
            FROM capability 
            WHERE tenant_id = :tenantId 
            GROUP BY category';
        
        $stmt = $conn->prepare($sql);
        $stmt->executeQuery(['tenantId' => $tenantId]);
        
        $results = $stmt->fetchAllAssociative();
        
        $counts = [];
        foreach ($results as $result) {
            $counts[$result['category']] = (int)$result['count'];
        }
        
        return $counts;
    }

    /**
     * Search capabilities by name or description.
     */
    public function search(string $tenantId, string $query): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.tenant = :tenantId')
            ->andWhere('c.name LIKE :query OR c.description LIKE :query OR c.identifier LIKE :query')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('query', '%' . $query . '%')
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get capability statistics for a tenant.
     */
    public function getStatistics(string $tenantId): array
    {
        $conn = $this->_em->getConnection();
        
        $sql = 'SELECT 
            COUNT(*) as total,
            COUNT(CASE WHEN is_enabled = true THEN 1 END) as enabled,
            COUNT(CASE WHEN is_installed = true THEN 1 END) as installed,
            COUNT(CASE WHEN is_configured = true THEN 1 END) as configured,
            COUNT(CASE WHEN is_enabled = true AND is_installed = true AND is_configured = true THEN 1 END) as ready
            FROM capability 
            WHERE tenant_id = :tenantId';
        
        $stmt = $conn->prepare($sql);
        $result = $stmt->executeQuery(['tenantId' => $tenantId]);
        
        return $result->fetchAssociative();
    }
}
