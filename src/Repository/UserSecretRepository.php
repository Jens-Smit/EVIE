<?php

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserSecret;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserSecret>
 */
class UserSecretRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserSecret::class);
    }

    /**
     * Findet ein Secret nach Key und User
     */
    public function findByKeyAndUser(string $key, User $user): ?UserSecret
    {
        return $this->findOneBy([
            'user' => $user,
            'key' => $key
        ]);
    }

    /**
     * Findet alle Secrets für einen User
     */
    public function findAllForUser(User $user): array
    {
        return $this->findBy([
            'user' => $user
        ], ['key' => 'ASC']);
    }

    /**
     * Findet alle aktiven Secrets für einen User
     */
    public function findAllActiveForUser(User $user): array
    {
        return $this->findBy([
            'user' => $user,
            'isActive' => true
        ], ['key' => 'ASC']);
    }

    /**
     * Findet Secrets nach Tool
     */
    public function findByTool(User $user, string $toolName): array
    {
        return $this->findBy([
            'user' => $user,
            'toolName' => $toolName,
            'isActive' => true
        ], ['key' => 'ASC']);
    }

    /**
     * Prüft, ob ein Secret für einen User existiert
     */
    public function existsForUser(string $key, User $user): bool
    {
        return $this->findOneBy([
            'user' => $user,
            'key' => $key,
            'isActive' => true
        ]) !== null;
    }

    /**
     * Löscht ein Secret nach Key und User
     */
    public function deleteByKeyAndUser(string $key, User $user): void
    {
        $secret = $this->findByKeyAndUser($key, $user);
        
        if ($secret) {
            $this->_em->remove($secret);
            $this->_em->flush();
        }
    }

    /**
     * Löscht alle Secrets für einen User
     */
    public function deleteAllForUser(User $user): void
    {
        $secrets = $this->findBy(['user' => $user]);
        
        foreach ($secrets as $secret) {
            $this->_em->remove($secret);
        }
        
        $this->_em->flush();
    }

    /**
     * Zählt die Anzahl der Secrets für einen User
     */
    public function countForUser(User $user): int
    {
        return $this->count(['user' => $user]);
    }

    /**
     * Findet Secrets, die nicht mit einem Tool verknüpft sind
     */
    public function findOrphanedSecrets(User $user): array
    {
        return $this->findBy([
            'user' => $user,
            'toolName' => null,
            'isActive' => true
        ]);
    }

    /**
     * Sucht nach Secrets, die einen bestimmten Text im Key oder in der Beschreibung enthalten
     */
    public function searchForUser(User $user, string $query): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.user = :user')
            ->andWhere('s.isActive = :active')
            ->andWhere('s.key LIKE :query OR s.description LIKE :query')
            ->setParameter('user', $user)
            ->setParameter('active', true)
            ->setParameter('query', '%' . $query . '%')
            ->orderBy('s.key', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
