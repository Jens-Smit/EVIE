<?php

namespace App\Repository\Automation;

use App\Entity\Automation\ScheduledTask;
use App\Entity\Tenant\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ScheduledTask>
 *
 * @method ScheduledTask|null find($id, $lockMode = null, $lockVersion = null)
 * @method ScheduledTask|null findOneBy(array $criteria, array $orderBy = null)
 * @method ScheduledTask[]    findAll()
 * @method ScheduledTask[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ScheduledTaskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ScheduledTask::class);
    }

    public function save(ScheduledTask $task, bool $flush = false): void
    {
        $this->_em->persist($task);
        if ($flush) {
            $this->_em->flush();
        }
    }

    public function remove(ScheduledTask $task, bool $flush = false): void
    {
        $this->_em->remove($task);
        if ($flush) {
            $this->_em->flush();
        }
    }

    /**
     * Find tasks by user ID with tenant isolation.
     */
    public function findByUser(string $userId): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.user = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('t.nextRunAt', 'ASC')
            ->addOrderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find tasks by tenant ID.
     */
    public function findByTenant(string $tenantId): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.tenant = :tenantId')
            ->setParameter('tenantId', $tenantId)
            ->orderBy('t.nextRunAt', 'ASC')
            ->addOrderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find tasks by organization ID.
     */
    public function findByOrganization(string $organizationId): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.organization = :organizationId')
            ->setParameter('organizationId', $organizationId)
            ->orderBy('t.nextRunAt', 'ASC')
            ->addOrderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find a task by ID with tenant isolation check.
     */
    public function findOneByIdAndTenant(string $id, string $tenantId): ?ScheduledTask
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.id = :id')
            ->andWhere('t.tenant = :tenantId')
            ->setParameter('id', $id)
            ->setParameter('tenantId', $tenantId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find a task by ID with user isolation check.
     */
    public function findOneByIdAndUser(string $id, string $userId): ?ScheduledTask
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.id = :id')
            ->andWhere('t.user = :userId')
            ->setParameter('id', $id)
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find due tasks (tasks that should run now).
     */
    public function findDueTasks(string $tenantId, \DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.tenant = :tenantId')
            ->andWhere('t.isActive = :isActive')
            ->andWhere('t.nextRunAt <= :now')
            ->andWhere('t.status != :statusRunning')
            ->andWhere('t.status != :statusLocked')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('isActive', true)
            ->setParameter('now', $now)
            ->setParameter('statusRunning', 'running')
            ->setParameter('statusLocked', 'locked')
            ->orderBy('t.nextRunAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find tasks that are currently locked.
     */
    public function findLockedTasks(string $tenantId): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.tenant = :tenantId')
            ->andWhere('t.lockId IS NOT NULL')
            ->andWhere('t.lockedAt IS NOT NULL')
            ->setParameter('tenantId', $tenantId)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find tasks by status.
     */
    public function findByStatus(string $tenantId, string $status): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.tenant = :tenantId')
            ->andWhere('t.status = :status')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('status', $status)
            ->orderBy('t.nextRunAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Check if a task exists for a user.
     */
    public function existsByIdAndUser(string $id, string $userId): bool
    {
        return $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->andWhere('t.id = :id')
            ->andWhere('t.user = :userId')
            ->setParameter('id', $id)
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * Check if a task exists for a tenant.
     */
    public function existsByIdAndTenant(string $id, string $tenantId): bool
    {
        return $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->andWhere('t.id = :id')
            ->andWhere('t.tenant = :tenantId')
            ->setParameter('id', $id)
            ->setParameter('tenantId', $tenantId)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * Get task count for a user.
     */
    public function countByUser(string $userId): int
    {
        return $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->andWhere('t.user = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Get task count for a tenant.
     */
    public function countByTenant(string $tenantId): int
    {
        return $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->andWhere('t.tenant = :tenantId')
            ->setParameter('tenantId', $tenantId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Find active tasks for a user.
     */
    public function findActiveByUser(string $userId): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.user = :userId')
            ->andWhere('t.isActive = :isActive')
            ->setParameter('userId', $userId)
            ->setParameter('isActive', true)
            ->orderBy('t.nextRunAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find tasks that need to be retried (failed tasks).
     */
    public function findFailedTasks(string $tenantId, int $maxRetries = 3): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.tenant = :tenantId')
            ->andWhere('t.failureCount < :maxRetries')
            ->andWhere('t.lastStatus = :lastStatus')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('maxRetries', $maxRetries)
            ->setParameter('lastStatus', 'failed')
            ->orderBy('t.lastRunAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
