<?php

namespace App\Repository;

use App\Entity\GoalEvaluation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GoalEvaluation>
 *
 * @method GoalEvaluation|null find($id, $lockMode = null, $lockVersion = null)
 * @method GoalEvaluation|null findOneBy(array $criteria, array $orderBy = null)
 * @method GoalEvaluation[]    findAll()
 * @method GoalEvaluation[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class GoalEvaluationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GoalEvaluation::class);
    }

    /**
     * Save a GoalEvaluation
     */
    public function save(GoalEvaluation $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Remove a GoalEvaluation
     */
    public function remove(GoalEvaluation $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Find evaluations by goal
     */
    public function findByGoal(int $goalId): array
    {
        return $this->findBy(
            ['goalId' => $goalId],
            ['createdAt' => 'DESC']
        );
    }

    /**
     * Find evaluations by user
     */
    public function findByUser(string $userIdentifier): array
    {
        return $this->createQueryBuilder('e')
            ->join('e.goal', 'g')
            ->where('g.userIdentifier = :user')
            ->setParameter('user', $userIdentifier)
            ->orderBy('e.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find recent evaluations (last N days)
     */
    public function findRecentEvaluations(int $days = 30): array
    {
        $date = new \DateTimeImmutable(sprintf('-%d days', $days));
        
        return $this->createQueryBuilder('e')
            ->where('e.createdAt >= :date')
            ->setParameter('date', $date)
            ->orderBy('e.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get average score for a goal
     */
    public function getAverageScoreForGoal(int $goalId): ?float
    {
        $evaluations = $this->findByGoal($goalId);
        
        if (empty($evaluations)) {
            return null;
        }

        $scores = array_filter(array_map(function(GoalEvaluation $e) {
            return $e->getScore();
        }, $evaluations));

        if (empty($scores)) {
            return null;
        }

        return array_sum($scores) / count($scores);
    }

    /**
     * Get success rate for a goal
     */
    public function getSuccessRateForGoal(int $goalId): float
    {
        $evaluations = $this->findByGoal($goalId);
        
        if (empty($evaluations)) {
            return 0.0;
        }

        $successCount = count(array_filter($evaluations, function(GoalEvaluation $e) {
            return $e->isSuccess();
        }));

        return ($successCount / count($evaluations)) * 100;
    }
}
