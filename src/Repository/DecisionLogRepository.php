<?php
// src/Repository/DecisionLogRepository.php

namespace App\Repository;

use App\Entity\DecisionLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DecisionLog>
 *
 * @method DecisionLog|null find($id, $lockMode = null, $lockVersion = null)
 * @method DecisionLog|null findOneBy(array $criteria, array $orderBy = null)
 * @method DecisionLog[]    findAll()
 * @method DecisionLog[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class DecisionLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DecisionLog::class);
    }

    public function save(DecisionLog $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(DecisionLog $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Gibt alle ausstehenden Entscheidungen zurück
     */
    public function findAllPending(): array
    {
        return $this->findBy(['status' => 'pending'], ['createdAt' => 'DESC']);
    }

    /**
     * Gibt Entscheidungen nach Typ zurück
     */
    public function findByType(string $type): array
    {
        return $this->findBy(['decisionType' => $type], ['createdAt' => 'DESC']);
    }

    /**
     * Gibt ausstehende Entscheidungen nach Typ zurück
     */
    public function findPendingByType(string $type): array
    {
        return $this->findBy([
            'decisionType' => $type,
            'status' => 'pending'
        ], ['createdAt' => 'DESC']);
    }

    /**
     * Gibt die neuesten Entscheidungen zurück
     */
    public function findRecent(int $limit = 10): array
    {
        return $this->findBy([], ['createdAt' => 'DESC'], $limit);
    }

    /**
     * Gibt Statistiken über Entscheidungen zurück
     */
    public function getStatistics(): array
    {
        $query = $this->createQueryBuilder('d');
        
        $query->select('COUNT(d.id) as total')
            ->andWhere('d.status = :status')
            ->setParameter('status', 'pending');
        $pendingCount = (int)$query->getQuery()->getSingleScalarResult();

        $query = $this->createQueryBuilder('d');
        $query->select('COUNT(d.id) as total')
            ->andWhere('d.status = :status')
            ->setParameter('status', 'approved');
        $approvedCount = (int)$query->getQuery()->getSingleScalarResult();

        $query = $this->createQueryBuilder('d');
        $query->select('COUNT(d.id) as total')
            ->andWhere('d.status = :status')
            ->setParameter('status', 'rejected');
        $rejectedCount = (int)$query->getQuery()->getSingleScalarResult();

        // Statistiken nach Typ
        $typeStats = [];
        $allDecisions = $this->findAll();
        foreach ($allDecisions as $decision) {
            $type = $decision->getDecisionType();
            if (!isset($typeStats[$type])) {
                $typeStats[$type] = [
                    'pending' => 0,
                    'approved' => 0,
                    'rejected' => 0,
                    'total' => 0
                ];
            }
            $typeStats[$type][$decision->getStatus()]++;
            $typeStats[$type]['total']++;
        }

        return [
            'total' => count($allDecisions),
            'pending' => $pendingCount,
            'approved' => $approvedCount,
            'rejected' => $rejectedCount,
            'by_type' => $typeStats,
        ];
    }

    /**
     * Gibt Entscheidungen eines bestimmten Users zurück
     */
    public function findByUser(string $userIdentifier): array
    {
        return $this->createQueryBuilder('d')
            ->leftJoin('d.userProfile', 'up')
            ->andWhere('up.userIdentifier = :userIdentifier')
            ->setParameter('userIdentifier', $userIdentifier)
            ->orderBy('d.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Gibt die neueste Entscheidung eines bestimmten Typs zurück
     */
    public function findLatestByType(string $type): ?DecisionLog
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.decisionType = :type')
            ->setParameter('type', $type)
            ->orderBy('d.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
