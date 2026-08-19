<?php

namespace App\Repository;

use App\Entity\ScheduledTask;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ScheduledTask>
 */
class ScheduledTaskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ScheduledTask::class);
    }

    /**
     * Findet alle Aufgaben für einen User
     */
    public function findAllForUser(User $user): array
    {
        return $this->findBy([
            'user' => $user
        ], ['scheduledAt' => 'ASC']);
    }

    /**
     * Findet alle ausstehenden Aufgaben für einen User
     */
    public function findPendingForUser(User $user): array
    {
        return $this->findBy([
            'user' => $user,
            'status' => ['pending', 'executing'],
            'isActive' => true
        ], ['scheduledAt' => 'ASC']);
    }

    /**
     * Findet alle wiederkehrenden Aufgaben für einen User
     */
    public function findRecurringForUser(User $user): array
    {
        return $this->findBy([
            'user' => $user,
            'isRecurring' => true,
            'isActive' => true
        ], ['nextExecutionAt' => 'ASC']);
    }

    /**
     * Findet alle Aufgaben, die in den nächsten X Minuten fällig sind
     */
    public function findDueWithinMinutes(int $minutes, DateTimeImmutable $now = null): array
    {
        $now = $now ?? new DateTimeImmutable();
        $future = $now->modify("+$minutes minutes");
        
        return $this->createQueryBuilder('t')
            ->where('t.status = :pending')
            ->andWhere('t.isActive = :active')
            ->andWhere('t.scheduledAt BETWEEN :now AND :future')
            ->setParameter('pending', 'pending')
            ->setParameter('active', true)
            ->setParameter('now', $now)
            ->setParameter('future', $future)
            ->orderBy('t.scheduledAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Findet alle überfälligen Aufgaben
     */
    public function findOverdue(DateTimeImmutable $now = null): array
    {
        $now = $now ?? new DateTimeImmutable();
        
        return $this->createQueryBuilder('t')
            ->where('t.status = :pending')
            ->andWhere('t.isActive = :active')
            ->andWhere('t.scheduledAt < :now')
            ->setParameter('pending', 'pending')
            ->setParameter('active', true)
            ->setParameter('now', $now)
            ->orderBy('t.scheduledAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Findet Aufgaben nach Typ
     */
    public function findByType(string $taskType, User $user = null): array
    {
        $qb = $this->createQueryBuilder('t')
            ->where('t.taskType = :type')
            ->setParameter('type', $taskType)
            ->orderBy('t.scheduledAt', 'ASC');
        
        if ($user) {
            $qb->andWhere('t.user = :user')
                ->setParameter('user', $user);
        }
        
        return $qb->getQuery()->getResult();
    }

    /**
     * Findet die nächste ausstehende Aufgabe für einen User
     */
    public function findNextForUser(User $user): ?ScheduledTask
    {
        $now = new DateTimeImmutable();
        
        return $this->createQueryBuilder('t')
            ->where('t.user = :user')
            ->andWhere('t.status = :pending')
            ->andWhere('t.isActive = :active')
            ->andWhere('t.scheduledAt >= :now')
            ->setParameter('user', $user)
            ->setParameter('pending', 'pending')
            ->setParameter('active', true)
            ->setParameter('now', $now)
            ->orderBy('t.scheduledAt', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Zählt die Anzahl der Aufgaben für einen User
     */
    public function countForUser(User $user): int
    {
        return $this->count(['user' => $user]);
    }

    /**
     * Zählt die Anzahl der ausstehenden Aufgaben für einen User
     */
    public function countPendingForUser(User $user): int
    {
        return $this->count([
            'user' => $user,
            'status' => ['pending', 'executing'],
            'isActive' => true
        ]);
    }

    /**
     * Löscht alle Aufgaben für einen User
     */
    public function deleteAllForUser(User $user): void
    {
        $tasks = $this->findBy(['user' => $user]);
        
        foreach ($tasks as $task) {
            $this->_em->remove($task);
        }
        
        $this->_em->flush();
    }

    /**
     * Findet Aufgaben, die heute fällig sind
     */
    public function findDueToday(User $user = null): array
    {
        $now = new DateTimeImmutable();
        $todayStart = $now->setTime(0, 0, 0);
        $todayEnd = $now->setTime(23, 59, 59);
        
        $qb = $this->createQueryBuilder('t')
            ->where('t.status = :pending')
            ->andWhere('t.isActive = :active')
            ->andWhere('t.scheduledAt BETWEEN :start AND :end')
            ->setParameter('pending', 'pending')
            ->setParameter('active', true)
            ->setParameter('start', $todayStart)
            ->setParameter('end', $todayEnd)
            ->orderBy('t.scheduledAt', 'ASC');
        
        if ($user) {
            $qb->andWhere('t.user = :user')
                ->setParameter('user', $user);
        }
        
        return $qb->getQuery()->getResult();
    }

    /**
     * Findet Aufgaben, die in der Zukunft liegen
     */
    public function findFuture(User $user, DateTimeImmutable $now = null): array
    {
        $now = $now ?? new DateTimeImmutable();
        
        return $this->createQueryBuilder('t')
            ->where('t.user = :user')
            ->andWhere('t.status = :pending')
            ->andWhere('t.isActive = :active')
            ->andWhere('t.scheduledAt > :now')
            ->setParameter('user', $user)
            ->setParameter('pending', 'pending')
            ->setParameter('active', true)
            ->setParameter('now', $now)
            ->orderBy('t.scheduledAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Findet die letzte ausgeführte Aufgabe für einen User
     */
    public function findLastExecutedForUser(User $user): ?ScheduledTask
    {
        return $this->createQueryBuilder('t')
            ->where('t.user = :user')
            ->andWhere('t.status = :executed')
            ->setParameter('user', $user)
            ->setParameter('executed', 'executed')
            ->orderBy('t.executedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
