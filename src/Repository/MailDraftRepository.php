<?php

namespace App\Repository;

use App\Entity\MailDraft;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MailDraft>
 *
 * @method MailDraft|null find($id, $lockMode = null, $lockVersion = null)
 * @method MailDraft|null findOneBy(array $criteria, array $orderBy = null)
 * @method MailDraft[]    findAll()
 * @method MailDraft[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class MailDraftRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MailDraft::class);
    }

    public function save(MailDraft $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(MailDraft $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Alle Entwuerfe eines Tenants, neueste zuerst.
     *
     * @return array<int, MailDraft>
     */
    public function findByUserIdentifier(string $userIdentifier): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.userIdentifier = :userIdentifier')
            ->setParameter('userIdentifier', $userIdentifier)
            ->orderBy('m.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Ausstehende Entwuerfe eines Tenants (HITL-Warteschlange).
     *
     * @return array<int, MailDraft>
     */
    public function findPendingByUserIdentifier(string $userIdentifier): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.userIdentifier = :userIdentifier')
            ->andWhere('m.status = :status')
            ->setParameter('userIdentifier', $userIdentifier)
            ->setParameter('status', MailDraft::STATUS_PENDING)
            ->orderBy('m.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
