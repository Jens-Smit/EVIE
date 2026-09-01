<?php

namespace App\Repository;

use App\Entity\Secret;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Secret>
 *
 * @method Secret|null find($id, $lockMode = null, $lockVersion = null)
 * @method Secret|null findOneBy(array $criteria, array $orderBy = null)
 * @method Secret[]    findAll()
 * @method Secret[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class SecretRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Secret::class);
    }

    /**
     * Speichere ein neues Secret
     */
    public function save(Secret $secret, bool $flush = false): void
    {
        $this->getEntityManager()->persist($secret);
        
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Lösche ein Secret
     */
    public function remove(Secret $secret, bool $flush = false): void
    {
        $this->getEntityManager()->remove($secret);
        
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Finde ein Secret nach Schlüsselname und Tenant
     */
    public function findOneByKeyAndUser(string $keyName, string $userIdentifier): ?Secret
    {
        return $this->findOneBy([
            'keyName' => $keyName,
            'userIdentifier' => $userIdentifier
        ]);
    }

    /**
     * Finde alle Secrets für einen Tenant
     */
    public function findByUser(string $userIdentifier): array
    {
        return $this->findBy(
            ['userIdentifier' => $userIdentifier],
            ['keyName' => 'ASC']
        );
    }

    /**
     * Finde Secrets nach Scope und Tenant
     */
    public function findByScopeAndUser(string $scope, string $userIdentifier): array
    {
        return $this->findBy(
            [
                'userIdentifier' => $userIdentifier,
                'scope' => $scope
            ],
            ['keyName' => 'ASC']
        );
    }

    /**
     * Aktualisiere das letzte Verwendungsdatum
     */
    public function updateLastUsed(Secret $secret, string $toolName): void
    {
        $secret->updateLastUsed($toolName);
        $this->getEntityManager()->flush();
    }
}
