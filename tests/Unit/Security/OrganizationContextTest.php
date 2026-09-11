<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\Organization;
use App\Entity\User;
use App\Repository\OrganizationRepository;
use App\Security\OrganizationContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Unit-Tests fuer OrganizationContext — deckt getUser, getOrganization,
 * getOrganizationId, hasRole, hasPermission, isOrganizationAdmin und
 * isSuperAdmin ab, inkl. der null-Pfade ohne eingeloggten User.
 */
final class OrganizationContextTest extends TestCase
{
    public function testGetUserReturnsNullWithoutToken(): void
    {
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn(null);
        $repo = $this->createMock(OrganizationRepository::class);
        $ctx = new OrganizationContext($tokenStorage, $repo);

        self::assertNull($ctx->getUser());
    }

    public function testGetOrganizationReturnsNullWithoutUser(): void
    {
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn(null);
        $repo = $this->createMock(OrganizationRepository::class);
        $ctx = new OrganizationContext($tokenStorage, $repo);

        self::assertNull($ctx->getOrganization());
        self::assertNull($ctx->getOrganizationId());
    }

    public function testGetOrganizationIdReturnsNullWhenUserHasNoOrganization(): void
    {
        $user = (new User())->setEmail('u@t.de');
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn(new UsernamePasswordToken($user, 'main', ['ROLE_USER']));
        $repo = $this->createMock(OrganizationRepository::class);
        $ctx = new OrganizationContext($tokenStorage, $repo);

        self::assertNull($ctx->getOrganizationId());
        self::assertNull($ctx->getOrganization());
    }

    public function testGetOrganizationReturnsOrganizationWhenSet(): void
    {
        $org = (new Organization())->setName('Acme');
        $user = (new User())->setEmail('u@t.de')->setOrganizationId('org-1');
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn(new UsernamePasswordToken($user, 'main', ['ROLE_USER']));
        $repo = $this->createMock(OrganizationRepository::class);
        $repo->expects(self::once())->method('find')->with('org-1')->willReturn($org);
        $ctx = new OrganizationContext($tokenStorage, $repo);

        self::assertSame('org-1', $ctx->getOrganizationId());
        self::assertSame($org, $ctx->getOrganization());
    }

    public function testHasRoleReturnsFalseWithoutUser(): void
    {
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn(null);
        $repo = $this->createMock(OrganizationRepository::class);
        $ctx = new OrganizationContext($tokenStorage, $repo);

        self::assertFalse($ctx->hasRole('ROLE_USER'));
        self::assertFalse($ctx->isOrganizationAdmin());
        self::assertFalse($ctx->isSuperAdmin());
    }

    public function testIsOrganizationAdminReturnsTrueWhenRolePresent(): void
    {
        $user = (new User())->setEmail('u@t.de');
        $user->setRoles(['ROLE_USER', 'ROLE_ORG_ADMIN']);
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        $repo = $this->createMock(OrganizationRepository::class);
        $ctx = new OrganizationContext($tokenStorage, $repo);

        self::assertTrue($ctx->isOrganizationAdmin());
        self::assertFalse($ctx->isSuperAdmin());
    }

    public function testIsSuperAdminReturnsTrueWhenRolePresent(): void
    {
        $user = (new User())->setEmail('admin@t.de');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        $repo = $this->createMock(OrganizationRepository::class);
        $ctx = new OrganizationContext($tokenStorage, $repo);

        self::assertTrue($ctx->isSuperAdmin());
    }

    public function testHasPermissionReturnsFalseWithoutOrganization(): void
    {
        $user = (new User())->setEmail('u@t.de');
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn(new UsernamePasswordToken($user, 'main', ['ROLE_USER']));
        $repo = $this->createMock(OrganizationRepository::class);
        $ctx = new OrganizationContext($tokenStorage, $repo);

        self::assertFalse($ctx->hasPermission('tools.approve'));
    }

    public function testHasPermissionDelegatesToOrganization(): void
    {
        $user = (new User())->setEmail('u@t.de')->setOrganizationId('org-1');
        $org = $this->createMock(Organization::class);
        $org->expects(self::once())->method('hasUserPermission')->with('u@t.de', 'tools.approve')->willReturn(true);
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn(new UsernamePasswordToken($user, 'main', ['ROLE_USER']));
        $repo = $this->createMock(OrganizationRepository::class);
        $repo->method('find')->with('org-1')->willReturn($org);
        $ctx = new OrganizationContext($tokenStorage, $repo);

        self::assertTrue($ctx->hasPermission('tools.approve'));
    }
}
