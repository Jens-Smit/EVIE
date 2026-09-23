<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OutboundAllowlistEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository fuer OutboundAllowlistEntry: Frontend-Freigaben fuer
 * ausgehende HTTP-Ziele (Blueprint §4.D/§5 - explizite Freigabe).
 */
class OutboundAllowlistEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OutboundAllowlistEntry::class);
    }

    /**
     * Alle aktiven Freigaben, sortiert nach Host-Pattern.
     *
     * @return list<OutboundAllowlistEntry>
     */
    public function findAllActive(): array
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.isActive = :isActive')
            ->setParameter('isActive', true)
            ->orderBy('o.hostPattern', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByHostPattern(string $hostPattern): ?OutboundAllowlistEntry
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.hostPattern = :hostPattern')
            ->setParameter('hostPattern', $hostPattern)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
