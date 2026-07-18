<?php

namespace App\Repository;

use App\Entity\AgentHistory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AgentHistory>
 */
class AgentHistoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AgentHistory::class);
    }

    public function save(AgentHistory $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(AgentHistory $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Finds all actions by a specific agent.
     */
    public function findByAgent(string $agentName): array
    {
        return $this->findBy(['agentName' => $agentName]);
    }

    /**
     * Finds all actions for a specific user.
     */
    public function findByUser(string $userIdentifier): array
    {
        return $this->createQueryBuilder('a')
            ->join('a.userProfile', 'u')
            ->where('u.userIdentifier = :userIdentifier')
            ->setParameter('userIdentifier', $userIdentifier)
            ->getQuery()
            ->getResult();
    }
}
