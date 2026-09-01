<?php

namespace App\Repository;

use App\Entity\Organization;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Organization>
 */
class OrganizationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Organization::class);
    }

    /**
     * Finde eine Organisation nach ID.
     */
    public function findOneById(int $id): ?Organization
    {
        return $this->find($id);
    }

    /**
     * Finde eine Organisation nach Slug.
     */
    public function findOneBySlug(string $slug): ?Organization
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /**
     * Finde alle aktiven Organisationen.
     */
    public function findAllActive(): array
    {
        return $this->findBy(['isActive' => true], ['name' => 'ASC']);
    }

    /**
     * Finde Organisationen nach Benutzer-Identifier.
     */
    public function findByUserIdentifier(string $userIdentifier): array
    {
        return $this->createQueryBuilder('o')
            ->join('o.users', 'u')
            ->where('u.userIdentifier = :userIdentifier')
            ->setParameter('userIdentifier', $userIdentifier)
            ->getQuery()
            ->getResult();
    }

    /**
     * Finde die Standard-Organisation (falls vorhanden).
     */
    public function findDefaultOrganization(): ?Organization
    {
        return $this->findOneBy(['slug' => 'default']);
    }

    /**
     * Erstelle oder finde eine Organisation.
     */
    public function findOrCreate(string $name, string $slug): Organization
    {
        $organization = $this->findOneBy(['slug' => $slug]);
        
        if (null === $organization) {
            $organization = new Organization();
            $organization->setName($name);
            $organization->setSlug($slug);
            $this->getEntityManager()->persist($organization);
            $this->getEntityManager()->flush();
        }
        
        return $organization;
    }
}
