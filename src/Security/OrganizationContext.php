<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Organization;
use App\Repository\OrganizationRepository;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Organization-Context für Multi-Org RBAC (P2).
 *
 * Hält die aktuelle Organisation für den authentifizierten User.
 * Wird für Tenant-Isolation und organisationsspezifische RBAC-Prüfungen genutzt.
 */
final class OrganizationContext
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly OrganizationRepository $organizationRepository,
    ) {
    }

    /**
     * Liefert die aktuelle Organisation des authentifizierten Users.
     * Falls kein User eingeloggt ist oder keine Organisation zugewiesen ist, wird null zurückgegeben.
     */
    public function getOrganization(): ?Organization
    {
        $user = $this->getUser();
        
        if (null === $user) {
            return null;
        }

        $organizationId = $user->getOrganizationId();
        
        if (null === $organizationId) {
            return null;
        }

        return $this->organizationRepository->find($organizationId);
    }

    /**
     * Liefert die Organisation-ID des aktuellen Users.
     */
    public function getOrganizationId(): ?string
    {
        $user = $this->getUser();
        
        if (null === $user) {
            return null;
        }

        return $user->getOrganizationId();
    }

    /**
     * Liefert den authentifizierten User.
     */
    public function getUser(): ?UserInterface
    {
        $token = $this->tokenStorage->getToken();
        
        if (null !== $token) {
            $user = $token->getUser();
            if ($user instanceof UserInterface) {
                return $user;
            }
        }

        return null;
    }

    /**
     * Prüft, ob der aktuelle User eine bestimmte Rolle in seiner Organisation hat.
     */
    public function hasRole(string $role): bool
    {
        $user = $this->getUser();
        
        if (null === $user) {
            return false;
        }

        return $user->hasRole($role);
    }

    /**
     * Prüft, ob der aktuelle User eine bestimmte Berechtigung in seiner Organisation hat.
     */
    public function hasPermission(string $permission): bool
    {
        $organization = $this->getOrganization();
        $user = $this->getUser();
        
        if (null === $organization || null === $user) {
            return false;
        }

        return $organization->hasUserPermission($user->getUserIdentifier(), $permission);
    }

    /**
     * Prüft, ob der aktuelle User Admin in seiner Organisation ist.
     */
    public function isOrganizationAdmin(): bool
    {
        return $this->hasRole('ROLE_ORG_ADMIN');
    }

    /**
     * Prüft, ob der aktuelle User Super-Admin ist (System-weite Berechtigungen).
     */
    public function isSuperAdmin(): bool
    {
        return $this->hasRole('ROLE_SUPER_ADMIN');
    }
}
