<?php
// src/Repository/StreamingSessionRepository.php

namespace App\Repository;

use App\Entity\StreamingSession;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository für Streaming-Sessions.
 * Bietet Methoden zum Verwalten von Streaming-Sessions.
 */
class StreamingSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StreamingSession::class);
    }

    /**
     * Finde eine Streaming-Session nach Session-ID.
     */
    public function findOneBySessionId(string $sessionId): ?StreamingSession
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.sessionId = :sessionId')
            ->setParameter('sessionId', $sessionId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Finde alle aktiven Streaming-Sessions.
     * @return StreamingSession[]
     */
    public function findAllActive(): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.status IN (:statuses)')
            ->setParameter('statuses', [StreamingSession::STATUS_PENDING, StreamingSession::STATUS_RUNNING])
            ->orderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Finde alle laufenden Streaming-Sessions.
     * @return StreamingSession[]
     */
    public function findAllRunning(): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.status = :status')
            ->setParameter('status', StreamingSession::STATUS_RUNNING)
            ->orderBy('s.startedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Finde alle abgeschlossenen Streaming-Sessions.
     * @return StreamingSession[]
     */
    public function findAllFinished(): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.status IN (:statuses)')
            ->setParameter('statuses', [
                StreamingSession::STATUS_COMPLETED,
                StreamingSession::STATUS_FAILED,
                StreamingSession::STATUS_CANCELLED
            ])
            ->orderBy('s.completedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Finde Streaming-Sessions nach User.
     * @return StreamingSession[]
     */
    public function findByUser(string $userIdentifier): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.userIdentifier = :userIdentifier')
            ->setParameter('userIdentifier', $userIdentifier)
            ->orderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Finde aktive Streaming-Sessions nach User.
     * @return StreamingSession[]
     */
    public function findActiveByUser(string $userIdentifier): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.userIdentifier = :userIdentifier')
            ->andWhere('s.status IN (:statuses)')
            ->setParameter('userIdentifier', $userIdentifier)
            ->setParameter('statuses', [StreamingSession::STATUS_PENDING, StreamingSession::STATUS_RUNNING])
            ->orderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Finde Streaming-Sessions nach Tool.
     * @return StreamingSession[]
     */
    public function findByTool(string $toolName): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.toolName = :toolName')
            ->setParameter('toolName', $toolName)
            ->orderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Finde Streaming-Sessions nach Status.
     * @return StreamingSession[]
     */
    public function findByStatus(string $status): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.status = :status')
            ->setParameter('status', $status)
            ->orderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Prüfe, ob eine Streaming-Session existiert.
     */
    public function existsBySessionId(string $sessionId): bool
    {
        return $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.sessionId = :sessionId')
            ->setParameter('sessionId', $sessionId)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * Lösche alle abgeschlossenen Streaming-Sessions, die älter als $days Tage sind.
     */
    public function deleteFinishedOlderThan(int $days): int
    {
        $cutoffDate = new \DateTimeImmutable(sprintf('-%d days', $days));

        return $this->createQueryBuilder('s')
            ->delete()
            ->andWhere('s.status IN (:statuses)')
            ->andWhere('s.completedAt < :cutoffDate')
            ->setParameter('statuses', [
                StreamingSession::STATUS_COMPLETED,
                StreamingSession::STATUS_FAILED,
                StreamingSession::STATUS_CANCELLED
            ])
            ->setParameter('cutoffDate', $cutoffDate)
            ->getQuery()
            ->execute();
    }

    /**
     * Zähle alle aktiven Streaming-Sessions.
     */
    public function countActive(): int
    {
        return $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.status IN (:statuses)')
            ->setParameter('statuses', [StreamingSession::STATUS_PENDING, StreamingSession::STATUS_RUNNING])
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Zähle alle Streaming-Sessions nach Status.
     * @return array<string, int>
     */
    public function countByStatus(): array
    {
        $results = $this->createQueryBuilder('s')
            ->select('s.status, COUNT(s.id) as count')
            ->groupBy('s.status')
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($results as $result) {
            $counts[$result['status']] = (int) $result['count'];
        }

        return $counts;
    }
}
