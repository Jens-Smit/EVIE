<?php

namespace App\Repository\AI;

use App\Entity\AI\Conversation;
use App\Entity\Tenant\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Conversation>
 *
 * @method Conversation|null find($id, $lockMode = null, $lockVersion = null)
 * @method Conversation|null findOneBy(array $criteria, array $orderBy = null)
 * @method Conversation[]    findAll()
 * @method Conversation[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ConversationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Conversation::class);
    }

    public function save(Conversation $conversation, bool $flush = false): void
    {
        $this->_em->persist($conversation);
        if ($flush) {
            $this->_em->flush();
        }
    }

    public function remove(Conversation $conversation, bool $flush = false): void
    {
        $this->_em->remove($conversation);
        if ($flush) {
            $this->_em->flush();
        }
    }

    /**
     * Find conversations by user ID with tenant isolation.
     */
    public function findByUser(string $userId): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.user = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('c.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find active conversations by user ID.
     */
    public function findActiveByUser(string $userId): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.user = :userId')
            ->andWhere('c.status = :status')
            ->setParameter('userId', $userId)
            ->setParameter('status', 'active')
            ->orderBy('c.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find conversations by tenant ID.
     */
    public function findByTenant(string $tenantId): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.tenant = :tenantId')
            ->setParameter('tenantId', $tenantId)
            ->orderBy('c.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find a conversation by ID with tenant isolation check.
     */
    public function findOneByIdAndTenant(string $id, string $tenantId): ?Conversation
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.id = :id')
            ->andWhere('c.tenant = :tenantId')
            ->setParameter('id', $id)
            ->setParameter('tenantId', $tenantId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find a conversation by ID with user isolation check.
     */
    public function findOneByIdAndUser(string $id, string $userId): ?Conversation
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.id = :id')
            ->andWhere('c.user = :userId')
            ->setParameter('id', $id)
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Check if a conversation exists for a user.
     */
    public function existsByIdAndUser(string $id, string $userId): bool
    {
        return $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.id = :id')
            ->andWhere('c.user = :userId')
            ->setParameter('id', $id)
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * Check if a conversation exists for a tenant.
     */
    public function existsByIdAndTenant(string $id, string $tenantId): bool
    {
        return $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.id = :id')
            ->andWhere('c.tenant = :tenantId')
            ->setParameter('id', $id)
            ->setParameter('tenantId', $tenantId)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * Find the last active conversation for a user.
     */
    public function findLastActiveByUser(string $userId): ?Conversation
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.user = :userId')
            ->andWhere('c.status = :status')
            ->setParameter('userId', $userId)
            ->setParameter('status', 'active')
            ->orderBy('c.updatedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Search conversations by title for a user.
     */
    public function searchByTitle(string $userId, string $query): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.user = :userId')
            ->andWhere('c.title LIKE :query')
            ->setParameter('userId', $userId)
            ->setParameter('query', '%' . $query . '%')
            ->orderBy('c.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get conversation count for a user.
     */
    public function countByUser(string $userId): int
    {
        return $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.user = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Get conversation count for a tenant.
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
}
