<?php

namespace App\Repository\Security;

use App\Entity\Security\Policy;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Policy>
 *
 * @method Policy|null find($id, $lockMode = null, $lockVersion = null)
 * @method Policy|null findOneBy(array $criteria, array $orderBy = null)
 * @method Policy[]    findAll()
 * @method Policy[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class PolicyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Policy::class);
    }

    public function save(Policy $policy, bool $flush = false): void
    {
        $this->_em->persist($policy);
        if ($flush) {
            $this->_em->flush();
        }
    }

    public function remove(Policy $policy, bool $flush = false): void
    {
        $this->_em->remove($policy);
        if ($flush) {
            $this->_em->flush();
        }
    }

    /**
     * Find policies by tenant ID.
     */
    public function findByTenant(string $tenantId): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.tenant = :tenantId')
            ->setParameter('tenantId', $tenantId)
            ->orderBy('p.priority', 'DESC')
            ->addOrderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find enabled policies by tenant ID.
     */
    public function findEnabledByTenant(string $tenantId): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.tenant = :tenantId')
            ->andWhere('p.isEnabled = :isEnabled')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('isEnabled', true)
            ->orderBy('p.priority', 'DESC')
            ->addOrderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find policies by identifier and tenant ID.
     */
    public function findOneByIdentifierAndTenant(string $identifier, string $tenantId): ?Policy
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.identifier = :identifier')
            ->andWhere('p.tenant = :tenantId')
            ->setParameter('identifier', $identifier)
            ->setParameter('tenantId', $tenantId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find policies by type.
     */
    public function findByType(string $tenantId, string $type): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.tenant = :tenantId')
            ->andWhere('p.policyType = :type')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('type', $type)
            ->orderBy('p.priority', 'DESC')
            ->addOrderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find policies that apply to a specific action.
     */
    public function findByAction(string $tenantId, string $action): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.tenant = :tenantId')
            ->andWhere('p.isEnabled = :isEnabled')
            ->andWhere(':action MEMBER OF p.actions OR \'*\' MEMBER OF p.actions')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('isEnabled', true)
            ->setParameter('action', $action)
            ->orderBy('p.priority', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find policies that apply to a specific resource.
     */
    public function findByResource(string $tenantId, string $resource): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.tenant = :tenantId')
            ->andWhere('p.isEnabled = :isEnabled')
            ->andWhere('p.resources IS NULL OR :resource MEMBER OF p.resources OR \'*\' MEMBER OF p.resources')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('isEnabled', true)
            ->setParameter('resource', $resource)
            ->orderBy('p.priority', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find policies that apply to a specific action and resource.
     */
    public function findByActionAndResource(string $tenantId, string $action, string $resource): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.tenant = :tenantId')
            ->andWhere('p.isEnabled = :isEnabled')
            ->andWhere(':action MEMBER OF p.actions OR \'*\' MEMBER OF p.actions')
            ->andWhere('p.resources IS NULL OR :resource MEMBER OF p.resources OR \'*\' MEMBER OF p.resources')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('isEnabled', true)
            ->setParameter('action', $action)
            ->setParameter('resource', $resource)
            ->orderBy('p.priority', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Check if a policy exists for a tenant.
     */
    public function existsByIdentifierAndTenant(string $identifier, string $tenantId): bool
    {
        return $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.identifier = :identifier')
            ->andWhere('p.tenant = :tenantId')
            ->setParameter('identifier', $identifier)
            ->setParameter('tenantId', $tenantId)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * Get policy count for a tenant.
     */
    public function countByTenant(string $tenantId): int
    {
        return $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.tenant = :tenantId')
            ->setParameter('tenantId', $tenantId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Get policy count by type for a tenant.
     */
    public function countByType(string $tenantId): array
    {
        $conn = $this->_em->getConnection();
        
        $sql = 'SELECT policy_type, COUNT(*) as count 
            FROM policy 
            WHERE tenant_id = :tenantId 
            GROUP BY policy_type';
        
        $stmt = $conn->prepare($sql);
        $stmt->executeQuery(['tenantId' => $tenantId]);
        
        $results = $stmt->fetchAllAssociative();
        
        $counts = [];
        foreach ($results as $result) {
            $counts[$result['policy_type']] = (int)$result['count'];
        }
        
        return $counts;
    }

    /**
     * Get policy statistics for a tenant.
     */
    public function getStatistics(string $tenantId): array
    {
        $conn = $this->_em->getConnection();
        
        $sql = 'SELECT 
            COUNT(*) as total,
            COUNT(CASE WHEN is_enabled = true THEN 1 END) as enabled,
            COUNT(CASE WHEN policy_type = \'action\' THEN 1 END) as action_policies,
            COUNT(CASE WHEN policy_type = \'resource\' THEN 1 END) as resource_policies,
            COUNT(CASE WHEN effect = \'allow\' THEN 1 END) as allow_policies,
            COUNT(CASE WHEN effect = \'deny\' THEN 1 END) as deny_policies,
            COUNT(CASE WHEN effect = \'ask\' THEN 1 END) as ask_policies
            FROM policy 
            WHERE tenant_id = :tenantId';
        
        $stmt = $conn->prepare($sql);
        $result = $stmt->executeQuery(['tenantId' => $tenantId]);
        
        return $result->fetchAssociative();
    }

    /**
     * Search policies by name or description.
     */
    public function search(string $tenantId, string $query): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.tenant = :tenantId')
            ->andWhere('p.name LIKE :query OR p.description LIKE :query OR p.identifier LIKE :query')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('query', '%' . $query . '%')
            ->orderBy('p.priority', 'DESC')
            ->addOrderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
