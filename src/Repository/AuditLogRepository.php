<?php

namespace AppRepository;

use AppEntityAuditLog;
use DoctrineBundleDoctrineBundleRepositoryServiceEntityRepository;
use DoctrinePersistenceManagerRegistry;

class AuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditLog::class);
    }

    /**
     * Speichere einen Audit-Log-Eintrag
     */
    public function log(string $action, ?int $userId, ?int $entityId, ?string $entityType, array $context = [], string $status = 'success', ?string $details = null): AuditLog
    {
        $log = new AuditLog();
        $log->setAction($action);
        $log->setUserId($userId);
        $log->setEntityId($entityId);
        $log->setEntityType($entityType);
        $log->setContext($context);
        $log->setStatus($status);
        $log->setDetails($details);

        $this->getEntityManager()->persist($log);
        $this->getEntityManager()->flush();

        return $log;
    }

    /**
     * Finde Logs nach Action
     */
    public function findByAction(string $action, int $limit = 100): array
    {
        return $this->findBy(['action' => $action], ['createdAt' => 'DESC'], $limit);
    }

    /**
     * Finde Logs nach User
     */
    public function findByUser(int $userId, int $limit = 100): array
    {
        return $this->findBy(['userId' => $userId], ['createdAt' => 'DESC'], $limit);
    }

    /**
     * Finde Logs nach Entity
     */
    public function findByEntity(string $entityType, int $entityId, int $limit = 100): array
    {
        return $this->findBy([
            'entityType' => $entityType,
            'entityId' => $entityId
        ], ['createdAt' => 'DESC'], $limit);
    }

    /**
     * Finde letzte Logs
     */
    public function findRecent(int $limit = 100): array
    {
        return $this->findBy([], ['createdAt' => 'DESC'], $limit);
    }

    /**
     * Finde Logs nach Status
     */
    public function findByStatus(string $status, int $limit = 100): array
    {
        return $this->findBy(['status' => $status], ['createdAt' => 'DESC'], $limit);
    }

    /**
     * Lösche alte Logs (älter als X Tage)
     */
    public function deleteOlderThan(int $days): int
    {
        $date = new DateTimeImmutable(sprintf('-%d days', $days));
        
        return $this->createQueryBuilder('a')
            ->delete()
            ->where('a.createdAt < :date')
            ->setParameter('date', $date)
            ->getQuery()
            ->execute();
    }
}
