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
}
