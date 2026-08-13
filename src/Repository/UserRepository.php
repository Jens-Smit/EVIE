<?php

namespace AppRepository;

use AppEntityUser;
use DoctrineBundleDoctrineBundleRepositoryServiceEntityRepository;
use DoctrinePersistenceManagerRegistry;
use SymfonyComponentSecurityCoreExceptionUnsupportedUserException;
use SymfonyComponentSecurityCoreUserPasswordUpgraderInterface;
use SymfonyComponentSecurityCoreUserUserInterface;

class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function upgradePassword(UserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    public function findByEmail(string $email): ?User
    {
        return $this->findOneBy(['email' => $email]);
    }

    public function findActiveByEmail(string $email): ?User
    {
        return $this->findOneBy([
            'email' => $email,
            'isActive' => true
        ]);
    }

    public function findUsersByRole(string $role): array
    {
        return $this->createQueryBuilder('u')
            ->where(':role MEMBER OF u.roles')
            ->setParameter('role', $role)
            ->getQuery()
            ->getResult();
    }

    public function findAdmins(): array
    {
        return $this->findUsersByRole('ROLE_ADMIN');
    }
}
