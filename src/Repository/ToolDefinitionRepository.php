<?php

namespace App\Repository;

use App\Entity\ToolDefinition;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ToolDefinition>
 */
class ToolDefinitionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ToolDefinition::class);
    }

    public function save(ToolDefinition $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(ToolDefinition $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Finds all approved tools.
     */
    public function findAllApproved(): array
    {
        return $this->findBy(['status' => 'approved']);
    }

    /**
     * Finds all pending tools.
     */
    public function findAllPending(): array
    {
        return $this->findBy(['status' => 'pending']);
    }

    /**
     * Finds approved tools for a specific tenant (P0-5 Tenant-Isolation).
     *
     * Liefert nur Tools, deren user_identifier mit dem angegebenen
     * Identifier uebereinstimmt. Tools mit user_identifier = NULL
     * (System-/MCP-Tools) werden ebenfalls geliefert, da sie keinen
     * Tenant-Bezug haben.
     */
    public function findApprovedForUser(string $userIdentifier): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.status = :status')
            ->andWhere('t.userIdentifier = :user OR t.userIdentifier IS NULL')
            ->setParameter('status', 'approved')
            ->setParameter('user', $userIdentifier)
            ->getQuery()
            ->getResult();
    }

    /**
     * Finds a tool definition by name, scoped to a tenant (P0-5).
     *
     * Verhindert, dass ein Tenant die ToolDefinition eines anderen Tenants
     * freigibt/ablehnt. System-Tools (user_identifier = NULL) werden immer
     * gefunden, da sie nicht tenant-spezifisch sind.
     */
    public function findOneByNameForUser(string $name, string $userIdentifier): ?ToolDefinition
    {
        return $this->createQueryBuilder('t')
            ->where('t.name = :name')
            ->andWhere('t.userIdentifier = :user OR t.userIdentifier IS NULL')
            ->setParameter('name', $name)
            ->setParameter('user', $userIdentifier)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
