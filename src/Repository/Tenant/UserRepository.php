<?php

namespace App\Repository\Tenant;

use App\Entity\Tenant\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 *
 * @method User|null find($id, $lockMode = null, $lockVersion = null)
 * @method User|null findOneBy(array $criteria, array $orderBy = null)
 * @method User[]    findAll()
 * @method User[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function save(User $user, bool $flush = false): void
    {
        $this->_em->persist($user);
        if ($flush) {
            $this->_em->flush();
        }
    }

    public function remove(User $user, bool $flush = false): void
    {
        $this->_em->remove($user);
        if ($flush) {
            $this->_em->flush();
        }
    }

    /**
     * Find a user by email within a specific tenant.
     */
    public function findOneByEmailAndTenant(string $email, string $tenantId): ?User
    {
        return $this->createQueryBuilder('u')
            ->join('u.tenant', 't')
            ->andWhere('u.email = :email')
            ->andWhere('t.id = :tenantId')
            ->setParameter('email', $email)
            ->setParameter('tenantId', $tenantId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find all users for a specific tenant.
     */
    public function findByTenant(string $tenantId): array
    {
        return $this->createQueryBuilder('u')
            ->join('u.tenant', 't')
            ->andWhere('t.id = :tenantId')
            ->setParameter('tenantId', $tenantId)
            ->orderBy('u.email', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find active users for a specific tenant.
     */
    public function findActiveByTenant(string $tenantId): array
    {
        return $this->createQueryBuilder('u')
            ->join('u.tenant', 't')
            ->andWhere('t.id = :tenantId')
            ->andWhere('u.isActive = :isActive')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('isActive', true)
            ->orderBy('u.email', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Check if an email exists within a tenant.
     */
    public function existsByEmailAndTenant(string $email, string $tenantId): bool
    {
        return $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->join('u.tenant', 't')
            ->andWhere('u.email = :email')
            ->andWhere('t.id = :tenantId')
            ->setParameter('email', $email)
            ->setParameter('tenantId', $tenantId)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * Find users by role within a tenant.
     */
    public function findByRoleAndTenant(string $role, string $tenantId): array
    {
        return $this->createQueryBuilder('u')
            ->join('u.tenant', 't')
            ->andWhere('t.id = :tenantId')
            ->andWhere(':role MEMBER OF u.roles')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('role', $role)
            ->orderBy('u.email', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
