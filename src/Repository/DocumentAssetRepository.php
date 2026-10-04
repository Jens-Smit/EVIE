<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DocumentAsset;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DocumentAsset>
 */
class DocumentAssetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DocumentAsset::class);
    }

    public function save(DocumentAsset $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(DocumentAsset $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * @return DocumentAsset[]
     */
    public function findByDocumentOrdered(int $documentId): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.document = :documentId')
            ->setParameter('documentId', $documentId)
            ->orderBy('a.position', 'ASC')
            ->addOrderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
