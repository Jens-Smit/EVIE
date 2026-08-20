<?php

namespace App\Repository\AI;

use App\Entity\AI\LLMConfiguration;
use App\Entity\Tenant\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LLMConfiguration>
 *
 * @method LLMConfiguration|null find($id, $lockMode = null, $lockVersion = null)
 * @method LLMConfiguration|null findOneBy(array $criteria, array $orderBy = null)
 * @method LLMConfiguration[]    findAll()
 * @method LLMConfiguration[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class LLMConfigurationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LLMConfiguration::class);
    }

    public function save(LLMConfiguration $configuration, bool $flush = false): void
    {
        $this->_em->persist($configuration);
        if ($flush) {
            $this->_em->flush();
        }
    }

    public function remove(LLMConfiguration $configuration, bool $flush = false): void
    {
        $this->_em->remove($configuration);
        if ($flush) {
            $this->_em->flush();
        }
    }

    /**
     * Find configurations by tenant ID.
     */
    public function findByTenant(string $tenantId): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.tenant = :tenantId')
            ->setParameter('tenantId', $tenantId)
            ->orderBy('c.priority', 'DESC')
            ->addOrderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find configurations by user ID.
     */
    public function findByUser(string $userId): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.user = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('c.priority', 'DESC')
            ->addOrderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find configurations by organization ID.
     */
    public function findByOrganization(string $organizationId): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.organization = :organizationId')
            ->setParameter('organizationId', $organizationId)
            ->orderBy('c.priority', 'DESC')
            ->addOrderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find the default configuration for a tenant.
     */
    public function findDefaultByTenant(string $tenantId): ?LLMConfiguration
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.tenant = :tenantId')
            ->andWhere('c.isDefault = :isDefault')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('isDefault', true)
            ->orderBy('c.priority', 'DESC')
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find the default configuration for a user.
     */
    public function findDefaultByUser(string $userId): ?LLMConfiguration
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.user = :userId')
            ->andWhere('c.isDefault = :isDefault')
            ->setParameter('userId', $userId)
            ->setParameter('isDefault', true)
            ->orderBy('c.priority', 'DESC')
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find fallback configurations for a tenant.
     */
    public function findFallbacksByTenant(string $tenantId): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.tenant = :tenantId')
            ->andWhere('c.isFallback = :isFallback')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('isFallback', true)
            ->orderBy('c.priority', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find configurations by provider.
     */
    public function findByProvider(string $provider, string $tenantId): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.tenant = :tenantId')
            ->andWhere('c.provider = :provider')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('provider', $provider)
            ->orderBy('c.priority', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find a configuration by name within a tenant.
     */
    public function findOneByNameAndTenant(string $name, string $tenantId): ?LLMConfiguration
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.tenant = :tenantId')
            ->andWhere('c.name = :name')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('name', $name)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find configurations that reference a specific secret.
     */
    public function findBySecretReference(string $secretKey, string $tenantId): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.tenant = :tenantId')
            ->andWhere('c.secretReference = :secretKey')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('secretKey', $secretKey)
            ->getQuery()
            ->getResult();
    }

    /**
     * Check if a configuration name exists within a scope.
     */
    public function existsByNameAndScope(
        string $name,
        ?string $userId = null,
        ?string $organizationId = null,
        string $tenantId
    ): bool {
        $qb = $this->createQueryBuilder('c')
            ->andWhere('c.name = :name')
            ->andWhere('c.tenant = :tenantId')
            ->setParameter('name', $name)
            ->setParameter('tenantId', $tenantId);

        if ($userId !== null) {
            $qb->andWhere('c.user = :userId')
                ->setParameter('userId', $userId);
        } elseif ($organizationId !== null) {
            $qb->andWhere('c.organization = :organizationId')
                ->setParameter('organizationId', $organizationId);
        } else {
            $qb->andWhere('c.user IS NULL AND c.organization IS NULL');
        }

        return $qb->select('COUNT(c.id)')
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }
}
