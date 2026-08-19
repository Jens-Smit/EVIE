<?php

namespace App\Repository;

use App\Entity\LLMConfiguration;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LLMConfiguration>
 */
class LLMConfigurationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LLMConfiguration::class);
    }

    /**
     * Findet die Standard-Konfiguration für einen User
     */
    public function findDefaultForUser(User $user): ?LLMConfiguration
    {
        return $this->findOneBy([
            'user' => $user,
            'isDefault' => true
        ], ['createdAt' => 'DESC']);
    }

    /**
     * Findet alle Konfigurationen für einen User
     */
    public function findAllForUser(User $user): array
    {
        return $this->findBy([
            'user' => $user
        ], ['isDefault' => 'DESC', 'createdAt' => 'DESC']);
    }

    /**
     * Findet eine Konfiguration nach Provider und User
     */
    public function findByProviderAndUser(string $provider, User $user): ?LLMConfiguration
    {
        return $this->findOneBy([
            'user' => $user,
            'provider' => $provider
        ]);
    }

    /**
     * Setzt eine Konfiguration als Standard für einen User
     */
    public function setAsDefault(LLMConfiguration $configuration): void
    {
        // Entferne den Standard-Status von allen anderen Konfigurationen des Users
        $user = $configuration->getUser();
        
        $this->createQueryBuilder('c')
            ->update()
            ->set('c.isDefault', false)
            ->where('c.user = :user')
            ->andWhere('c.id != :configId')
            ->setParameter('user', $user)
            ->setParameter('configId', $configuration->getId())
            ->getQuery()
            ->execute();

        // Setze die neue Konfiguration als Standard
        $configuration->setIsDefault(true);
        $this->_em->flush();
    }

    /**
     * Löscht alle Konfigurationen für einen User
     */
    public function deleteAllForUser(User $user): void
    {
        $configurations = $this->findBy(['user' => $user]);
        
        foreach ($configurations as $configuration) {
            $this->_em->remove($configuration);
        }
        
        $this->_em->flush();
    }

    /**
     * Zählt die Anzahl der Konfigurationen für einen User
     */
    public function countForUser(User $user): int
    {
        return $this->count(['user' => $user]);
    }

    /**
     * Findet Konfigurationen, die einen benutzerdefinierten Provider verwenden
     */
    public function findCustomProviderConfigurations(): array
    {
        return $this->findBy(['provider' => 'custom']);
    }
}
