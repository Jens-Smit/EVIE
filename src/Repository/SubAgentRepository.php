<?php

namespace App\Repository;

use App\Entity\SubAgent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SubAgent>
 *
 * @method SubAgent|null find($id, $lockMode = null, $lockVersion = null)
 * @method SubAgent|null findOneBy(array $criteria, array $orderBy = null)
 * @method SubAgent[]    findAll()
 * @method SubAgent[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class SubAgentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SubAgent::class);
    }

    public function save(SubAgent $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(SubAgent $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Find sub-agents by user
     */
    public function findByUser(int $userId): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.user = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find active sub-agents
     */
    public function findActive(): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.status = :status')
            ->setParameter('status', 'active')
            ->orderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find sub-agents with specific capability
     */
    public function findByCapability(string $capability): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere(':capability MEMBER OF s.capabilities')
            ->setParameter('capability', $capability)
            ->orderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
