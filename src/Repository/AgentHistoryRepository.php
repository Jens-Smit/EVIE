<?php

namespace App\Repository;

use App\Entity\AgentHistory;
use App\Entity\UserProfile;
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
     * Finds all actions by a specific action/agent.
     */
    public function findByAction(string $action): array
    {
        return $this->findBy(['action' => $action]);
    }

    /**
     * Finds all actions for a specific user.
     */
    public function findByUserIdentifier(string $userIdentifier): array
    {
        return $this->createQueryBuilder('a')
            ->join('a.user', 'u')
            ->where('u.userIdentifier = :userIdentifier')
            ->setParameter('userIdentifier', $userIdentifier)
            ->getQuery()
            ->getResult();
    }

    /**
     * Finds a UserProfile by its identifier.
     */
    public function findUserByIdentifier(string $userIdentifier): ?UserProfile
    {
        $userProfileRepo = $this->getEntityManager()->getRepository(UserProfile::class);

        return $userProfileRepo->findOneBy(['userIdentifier' => $userIdentifier]);
    }
}
