<?php

namespace App\Repository\Security;

use App\Entity\Security\UserSecret;
use App\Entity\Tenant\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserSecret>
 *
 * @method UserSecret|null find($id, $lockMode = null, $lockVersion = null)
 * @method UserSecret|null findOneBy(array $criteria, array $orderBy = null)
 * @method UserSecret[]    findAll()
 * @method UserSecret[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class UserSecretRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserSecret::class);
    }

    /**
     * Save a secret with automatic timestamp update.
     */
    public function save(UserSecret $secret, bool $flush = false): void
    {
        $this->_em->persist($secret);
        if ($flush) {
            $this->_em->flush();
        }
    }

    /**
     * Remove a secret.
     */
    public function remove(UserSecret $secret, bool $flush = false): void
    {
        $this->_em->remove($secret);
        if ($flush) {
            $this->_em->flush();
        }
    }

    /**
     * Find a secret by key for a specific user.
     * Ensures tenant isolation by checking user ownership.
     */
    public function findOneByKeyAndUser(string $secretKey, User $user): ?UserSecret
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.secretKey = :secretKey')
            ->andWhere('s.user = :user')
            ->setParameter('secretKey', $secretKey)
            ->setParameter('user', $user)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find all secrets for a specific user.
     * Automatically scoped to the user's tenant.
     */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.user = :user')
            ->setParameter('user', $user)
            ->orderBy('s.secretKey', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find secrets by tenant ID.
     * Used for admin operations within a tenant.
     */
    public function findByTenant(string $tenantId): array
    {
        return $this->createQueryBuilder('s')
            ->join('s.user', 'u')
            ->join('u.tenant', 't')
            ->andWhere('t.id = :tenantId')
            ->setParameter('tenantId', $tenantId)
            ->orderBy('s.secretKey', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Check if a secret key exists for a user.
     */
    public function existsByKeyAndUser(string $secretKey, User $user): bool
    {
        return $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.secretKey = :secretKey')
            ->andWhere('s.user = :user')
            ->setParameter('secretKey', $secretKey)
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * Delete all secrets for a user.
     * Used when a user is deleted.
     */
    public function deleteByUser(User $user, bool $flush = false): int
    {
        $result = $this->createQueryBuilder('s')
            ->delete()
            ->andWhere('s.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();

        if ($flush) {
            $this->_em->flush();
        }

        return $result;
    }

    /**
     * Find secrets that need re-encryption (for key rotation).
     */
    public function findForReEncryption(string $currentKeyVersion, string $tenantId): array
    {
        return $this->createQueryBuilder('s')
            ->join('s.user', 'u')
            ->join('u.tenant', 't')
            ->andWhere('s.keyVersion != :currentKeyVersion')
            ->andWhere('t.id = :tenantId')
            ->setParameter('currentKeyVersion', $currentKeyVersion)
            ->setParameter('tenantId', $tenantId)
            ->getQuery()
            ->getResult();
    }

    // Static method for easy access
    public static function getEntityClass(): string
    {
        return UserSecret::class;
    }
}
