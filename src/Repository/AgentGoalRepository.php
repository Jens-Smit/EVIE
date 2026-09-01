<?php

namespace App\Repository;

use App\Entity\AgentGoal;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AgentGoal>
 *
 * @method AgentGoal|null find($id, $lockMode = null, $lockVersion = null)
 * @method AgentGoal|null findOneBy(array $criteria, array $orderBy = null)
 * @method AgentGoal[]    findAll()
 * @method AgentGoal[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class AgentGoalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AgentGoal::class);
    }

    /**
     * Save an AgentGoal
     */
    public function save(AgentGoal $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Remove an AgentGoal
     */
    public function remove(AgentGoal $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Find all active goals for a user
     */
    public function findActiveByUser(string $userIdentifier): array
    {
        return $this->findBy([
            'userIdentifier' => $userIdentifier,
            'status' => 'active',
            'isApproved' => true,
        ], ['nextRunAt' => 'ASC']);
    }

    /**
     * Find all goals for a user
     */
    public function findByUser(string $userIdentifier): array
    {
        return $this->findBy(
            ['userIdentifier' => $userIdentifier],
            ['createdAt' => 'DESC']
        );
    }

    /**
     * Find goals that are due to run (nextRunAt <= now)
     */
    public function findDueGoals(): array
    {
        $now = new DateTimeImmutable();
        
        return $this->createQueryBuilder('g')
            ->where('g.status = :status')
            ->andWhere('g.isApproved = :approved')
            ->andWhere('g.nextRunAt IS NOT NULL')
            ->andWhere('g.nextRunAt <= :now')
            ->setParameter('status', 'active')
            ->setParameter('approved', true)
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find goals that are due to run for a specific user
     */
    public function findDueGoalsByUser(string $userIdentifier): array
    {
        $now = new DateTimeImmutable();
        
        return $this->createQueryBuilder('g')
            ->where('g.userIdentifier = :user')
            ->andWhere('g.status = :status')
            ->andWhere('g.isApproved = :approved')
            ->andWhere('g.nextRunAt IS NOT NULL')
            ->andWhere('g.nextRunAt <= :now')
            ->setParameter('user', $userIdentifier)
            ->setParameter('status', 'active')
            ->setParameter('approved', true)
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find goals that need approval
     */
    public function findPendingApproval(string $userIdentifier): array
    {
        return $this->findBy([
            'userIdentifier' => $userIdentifier,
            'requiresApproval' => true,
            'isApproved' => false,
        ]);
    }

    /**
     * Update the next run time for a goal
     */
    public function updateNextRunAt(AgentGoal $goal): void
    {
        $nextRunAt = $goal->calculateNextRunAt();
        if (null !== $nextRunAt) {
            $goal->setNextRunAt($nextRunAt);
            $goal->setUpdatedAt(new DateTimeImmutable());
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Mark a goal as completed with result
     */
    public function markCompleted(AgentGoal $goal, array $result): void
    {
        $goal->setLastRunAt(new DateTimeImmutable());
        $goal->setLastResult($result);
        $goal->incrementExecutionCount();
        $goal->setStatus('completed');
        $goal->setUpdatedAt(new DateTimeImmutable());
        
        $this->getEntityManager()->flush();
    }

    /**
     * Activate a goal
     */
    public function activate(AgentGoal $goal): void
    {
        $goal->setStatus('active');
        $goal->setUpdatedAt(new DateTimeImmutable());
        
        // Calculate first next run time
        $nextRunAt = $goal->calculateNextRunAt();
        if (null !== $nextRunAt) {
            $goal->setNextRunAt($nextRunAt);
        }
        
        $this->getEntityManager()->flush();
    }

    /**
     * Pause a goal
     */
    public function pause(AgentGoal $goal): void
    {
        $goal->setStatus('paused');
        $goal->setUpdatedAt(new DateTimeImmutable());
        
        $this->getEntityManager()->flush();
    }

    /**
     * Approve a goal for execution
     */
    public function approve(AgentGoal $goal): void
    {
        $goal->setIsApproved(true);
        $goal->setUpdatedAt(new DateTimeImmutable());
        
        // If approved and active, calculate next run
        if ($goal->getStatus() === 'active') {
            $nextRunAt = $goal->calculateNextRunAt();
            if (null !== $nextRunAt) {
                $goal->setNextRunAt($nextRunAt);
            }
        }
        
        $this->getEntityManager()->flush();
    }
}
