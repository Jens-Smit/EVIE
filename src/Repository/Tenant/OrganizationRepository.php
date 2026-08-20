<?php

namespace App\Repository\Tenant;

use App\Entity\Tenant\Organization;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Organization>
 *
 * @method Organization|null find($id, $lockMode = null, $lockVersion = null)
 * @method Organization|null findOneBy(array $criteria, array $orderBy = null)
 * @method Organization[]    findAll()
 * @method Organization[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class OrganizationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Organization::class);
    }

    public function save(Organization $organization, bool $flush = false): void
    {
        $this->_em->persist($organization);
        if ($flush) {
            $this->_em->flush();
        }
    }

    public function remove(Organization $organization, bool $flush = false): void
    {
        $this->_em->remove($organization);
        if ($flush) {
            $this->_em->flush();
        }
    }

    public function findByTenant(string $tenantId): array
    {
        return $this->createQueryBuilder('o')
            ->join('o.tenant', 't')
            ->andWhere('t.id = :tenantId')
            ->setParameter('tenantId', $tenantId)
            ->orderBy('o.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findActiveByTenant(string $tenantId): array
    {
        return $this->createQueryBuilder('o')
            ->join('o.tenant', 't')
            ->andWhere('t.id = :tenantId')
            ->andWhere('o.isActive = :isActive')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('isActive', true)
            ->orderBy('o.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByNameAndTenant(string $name, string $tenantId): ?Organization
    {
        return $this->createQueryBuilder('o')
            ->join('o.tenant', 't')
            ->andWhere('o.name = :name')
            ->andWhere('t.id = :tenantId')
            ->setParameter('name', $name)
            ->setParameter('tenantId', $tenantId)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
