<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Organization;
use App\Entity\User;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Unit-Tests fuer Organization-Entity.
 */
final class OrganizationTest extends TestCase
{
    public function testGettersAndSetters(): void
    {
        $org = new Organization();
        $created = new DateTimeImmutable('2025-01-01');
        $updated = new DateTimeImmutable('2025-01-02');
        $org
            ->setName('Acme')
            ->setSlug('acme')
            ->setDescription('A company')
            ->setIsActive(true)
            ->setCreatedAt($created)
            ->setUpdatedAt($updated)
            ->setSettings(['key' => 'value'])
            ->setRbacConfig(['roles' => ['ROLE_ADMIN' => ['permissions' => ['read']]]]);

        self::assertSame('Acme', $org->getName());
        self::assertSame('acme', $org->getSlug());
        self::assertSame('A company', $org->getDescription());
        self::assertTrue($org->isActive());
        self::assertSame($created, $org->getCreatedAt());
        self::assertSame($updated, $org->getUpdatedAt());
        self::assertSame(['key' => 'value'], $org->getSettings());
        self::assertSame(['roles' => ['ROLE_ADMIN' => ['permissions' => ['read']]]], $org->getRbacConfig());
    }

    public function testDefaults(): void
    {
        $org = new Organization();
        self::assertNull($org->getId());
        self::assertNull($org->getName());
        self::assertNull($org->getSlug());
        self::assertTrue($org->isActive());
        self::assertNull($org->getDescription());
        self::assertCount(0, $org->getUsers());
        self::assertInstanceOf(DateTimeImmutable::class, $org->getCreatedAt());
    }

    public function testAddUserSetsOrganizationId(): void
    {
        $org = $this->createOrgWithId(1);
        $user = new User();
        $user->setEmail('u@example.com');

        $org->addUser($user);
        self::assertCount(1, $org->getUsers());
        self::assertSame('1', $user->getOrganizationId());
    }

    public function testAddUserDoesNotDuplicate(): void
    {
        $org = new Organization();
        $user = new User();
        $user->setEmail('u@example.com');

        $org->addUser($user);
        $org->addUser($user);
        self::assertCount(1, $org->getUsers());
    }

    public function testRemoveUserClearsOrganizationIdWhenMatching(): void
    {
        $org = $this->createOrgWithId(1);
        $user = new User();
        $user->setEmail('u@example.com');
        $org->addUser($user);
        self::assertSame('1', $user->getOrganizationId());

        $org->removeUser($user);
        self::assertCount(0, $org->getUsers());
        self::assertNull($user->getOrganizationId());
    }

    public function testRemoveUserDoesNotClearWhenOrganizationIdDiffers(): void
    {
        $org = $this->createOrgWithId(1);
        $user = new User();
        $user->setEmail('u@example.com');
        // Setze die Org-ID auf einen anderen Wert als die Org-Id; addUser
        // ueberschreibt sie, daher adden wir erst und setzen dann manuell.
        $org->addUser($user);
        $user->setOrganizationId(99);
        $org->removeUser($user);

        self::assertSame('99', $user->getOrganizationId());
    }

    public function testHasUserWithRole(): void
    {
        $org = new Organization();
        $user = new User();
        $user->setEmail('admin@example.com');
        $user->setRoles(['ROLE_ADMIN']);
        $org->addUser($user);

        self::assertTrue($org->hasUserWithRole('admin@example.com', 'ROLE_ADMIN'));
        self::assertFalse($org->hasUserWithRole('admin@example.com', 'ROLE_SUPER'));
        self::assertFalse($org->hasUserWithRole('nobody@example.com', 'ROLE_ADMIN'));
    }

    public function testHasUserPermission(): void
    {
        $org = new Organization();
        $user = new User();
        $user->setEmail('admin@example.com');
        $user->setRoles(['ROLE_ADMIN']);
        $org->addUser($user);
        $org->setRbacConfig(['roles' => ['ROLE_ADMIN' => ['permissions' => ['read', 'write']]]]);

        self::assertTrue($org->hasUserPermission('admin@example.com', 'read'));
        self::assertFalse($org->hasUserPermission('admin@example.com', 'delete'));
        self::assertFalse($org->hasUserPermission('nobody@example.com', 'read'));
    }

    public function testHasUserPermissionWithoutRbacConfig(): void
    {
        $org = new Organization();
        $user = new User();
        $user->setEmail('u@example.com');
        $org->addUser($user);

        self::assertFalse($org->hasUserPermission('u@example.com', 'read'));
    }

    private function createOrgWithId(int $id): Organization
    {
        $org = new Organization();
        $ref = new \ReflectionProperty(Organization::class, 'id');
        $ref->setValue($org, $id);

        return $org;
    }
}
