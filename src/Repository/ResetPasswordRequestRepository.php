<?php

namespace App\Repository;

use App\Entity\ResetPasswordRequest;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ResetPasswordRequest>
 */
class ResetPasswordRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ResetPasswordRequest::class);
    }

    public function save(ResetPasswordRequest $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(ResetPasswordRequest $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Entfernt alle Reset-Requests eines Benutzers.
     */
    public function removeForUser(User $user): void
    {
        $this->createQueryBuilder('r')
            ->delete()
            ->where('r.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }

    /**
     * Entfernt alle abgelaufenen Reset-Requests.
     */
    public function removeExpired(): int
    {
        return (int) $this->createQueryBuilder('r')
            ->delete()
            ->where('r.expiresAt <= :now')
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getResult();
    }

    public function findBySelector(string $selector): ?ResetPasswordRequest
    {
        return $this->findOneBy(['selector' => $selector]);
    }
}
