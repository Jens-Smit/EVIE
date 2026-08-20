<?php

namespace App\Repository\AI;

use App\Entity\AI\AgentExecution;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AgentExecution>
 *
 * @method AgentExecution|null find($id, $lockMode = null, $lockVersion = null)
 * @method AgentExecution|null findOneBy(array $criteria, array $orderBy = null)
 * @method AgentExecution[]    findAll()
 * @method AgentExecution[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class AgentExecutionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AgentExecution::class);
    }

    public function save(AgentExecution $execution, bool $flush = false): void
    {
        $this->_em->persist($execution);
        if ($flush) {
            $this->_em->flush();
        }
    }

    public function remove(AgentExecution $execution, bool $flush = false): void
    {
        $this->_em->remove($execution);
        if ($flush) {
            $this->_em->flush();
        }
    }

    /**
     * Find executions by user ID with tenant isolation.
     */
    public function findByUser(string $userId): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.user = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('e.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find executions by tenant ID.
     */
    public function findByTenant(string $tenantId): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.tenant = :tenantId')
            ->setParameter('tenantId', $tenantId)
            ->orderBy('e.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find executions by conversation ID.
     */
    public function findByConversation(string $conversationId): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.conversation = :conversationId')
            ->setParameter('conversationId', $conversationId)
            ->orderBy('e.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find a execution by ID with tenant isolation check.
     */
    public function findOneByIdAndTenant(string $id, string $tenantId): ?AgentExecution
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.id = :id')
            ->andWhere('e.tenant = :tenantId')
            ->setParameter('id', $id)
            ->setParameter('tenantId', $tenantId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find a execution by ID with user isolation check.
     */
    public function findOneByIdAndUser(string $id, string $userId): ?AgentExecution
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.id = :id')
            ->andWhere('e.user = :userId')
            ->setParameter('id', $id)
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find executions by status.
     */
    public function findByStatus(string $tenantId, string $status): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.tenant = :tenantId')
            ->andWhere('e.status = :status')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('status', $status)
            ->orderBy('e.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find queued executions.
     */
    public function findQueued(string $tenantId): array
    {
        return $this->findByStatus($tenantId, 'queued');
    }

    /**
     * Find running executions.
     */
    public function findRunning(string $tenantId): array
    {
        return $this->findByStatus($tenantId, 'running');
    }

    /**
     * Find failed executions that can be retried.
     */
    public function findRetryable(string $tenantId, int $maxRetries = 3): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.tenant = :tenantId')
            ->andWhere('e.status = :status')
            ->andWhere('e.retryCount < :maxRetries')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('status', 'failed')
            ->setParameter('maxRetries', $maxRetries)
            ->orderBy('e.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find root executions (executions without parent).
     */
    public function findRootExecutions(string $tenantId): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.tenant = :tenantId')
            ->andWhere('e.parentExecution IS NULL')
            ->setParameter('tenantId', $tenantId)
            ->orderBy('e.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find child executions for a parent execution.
     */
    public function findChildren(string $parentExecutionId): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.parentExecution = :parentExecutionId')
            ->setParameter('parentExecutionId', $parentExecutionId)
            ->orderBy('e.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find executions by idempotency key.
     */
    public function findByIdempotencyKey(string $idempotencyKey): ?AgentExecution
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.idempotencyKey = :idempotencyKey')
            ->setParameter('idempotencyKey', $idempotencyKey)
            ->orderBy('e.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find executions by correlation ID.
     */
    public function findByCorrelationId(string $correlationId): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.correlationId = :correlationId')
            ->setParameter('correlationId', $correlationId)
            ->orderBy('e.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Check if an execution exists for a user.
     */
    public function existsByIdAndUser(string $id, string $userId): bool
    {
        return $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->andWhere('e.id = :id')
            ->andWhere('e.user = :userId')
            ->setParameter('id', $id)
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * Check if an execution exists for a tenant.
     */
    public function existsByIdAndTenant(string $id, string $tenantId): bool
    {
        return $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->andWhere('e.id = :id')
            ->andWhere('e.tenant = :tenantId')
            ->setParameter('id', $id)
            ->setParameter('tenantId', $tenantId)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * Get execution count for a user.
     */
    public function countByUser(string $userId): int
    {
        return $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->andWhere('e.user = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Get execution count for a tenant.
     */
    public function countByTenant(string $tenantId): int
    {
        return $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->andWhere('e.tenant = :tenantId')
            ->setParameter('tenantId', $tenantId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Get the last execution for a user.
     */
    public function findLastByUser(string $userId): ?AgentExecution
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.user = :userId')
            ->orderBy('e.createdAt', 'DESC')
            ->setMaxResults(1)
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Get statistics for a tenant.
     */
    public function getStatisticsByTenant(string $tenantId): array
    {
        $conn = $this->_em->getConnection();
        
        $sql = 'SELECT 
            COUNT(*) as total,
            COUNT(CASE WHEN status = \'created\' THEN 1 END) as created,
            COUNT(CASE WHEN status = \'queued\' THEN 1 END) as queued,
            COUNT(CASE WHEN status = \'running\' THEN 1 END) as running,
            COUNT(CASE WHEN status = \'completed\' THEN 1 END) as completed,
            COUNT(CASE WHEN status = \'failed\' THEN 1 END) as failed,
            AVG(duration) as avg_duration,
            MAX(duration) as max_duration
            FROM agent_execution 
            WHERE tenant_id = :tenantId';
        
        $stmt = $conn->prepare($sql);
        $stmt->executeQuery(['tenantId' => $tenantId]);
        
        return $stmt->fetchAssociative();
    }
}
