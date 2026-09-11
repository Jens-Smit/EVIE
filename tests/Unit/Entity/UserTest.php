<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\User;
use App\Entity\UserProfile;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Unit-Tests fuer User-Entity.
 */
final class UserTest extends TestCase
{
    public function testConstructorAndDefaults(): void
    {
        $user = new User();

        self::assertNull($user->getId());
        self::assertNull($user->getEmail());
        self::assertSame(['ROLE_USER'], $user->getRoles());
        self::assertTrue($user->isActive());
        self::assertFalse($user->isOnboardingComplete());
        self::assertNull($user->getProfile());
        self::assertNull($user->getOrganizationId());
        self::assertSame('', $user->getFullName());
        self::assertInstanceOf(DateTimeImmutable::class, $user->getCreatedAt());
    }

    public function testEmailAndIdentifier(): void
    {
        $user = new User();
        $user->setEmail('max@example.com');

        self::assertSame('max@example.com', $user->getEmail());
        self::assertSame('max@example.com', $user->getUserIdentifier());
    }

    public function testRoles(): void
    {
        $user = new User();
        $user->setRoles(['ROLE_ADMIN']);

        self::assertSame(['ROLE_ADMIN', 'ROLE_USER'], $user->getRoles());

        $user->addRole('ROLE_ADMIN');
        self::assertSame(['ROLE_ADMIN', 'ROLE_USER'], $user->getRoles());

        $user->removeRole('ROLE_ADMIN');
        // removeRole laesst den internen Array-Index erhalten; array_unique
        // behaelt diesen bei. Pruefe therefore inhaltlich.
        self::assertSame(['ROLE_USER'], array_values($user->getRoles()));
        self::assertFalse($user->hasRole('ROLE_ADMIN'));
    }

    public function testPassword(): void
    {
        $user = new User();
        $user->setPassword('hashed');

        self::assertSame('hashed', $user->getPassword());
    }

    public function testFullName(): void
    {
        $user = new User();
        $user->setFirstName('Max');
        $user->setLastName('Mustermann');

        self::assertSame('Max Mustermann', $user->getFullName());
    }

    public function testActiveAndOnboarding(): void
    {
        $user = new User();
        $user->setIsActive(false);
        $user->setOnboardingComplete(true);

        self::assertFalse($user->isActive());
        self::assertTrue($user->isOnboardingComplete());
    }

    public function testTimestamps(): void
    {
        $user = new User();
        $created = new DateTimeImmutable('2025-01-01');
        $updated = new DateTimeImmutable('2025-01-02');
        $lastLogin = new DateTimeImmutable('2025-01-03');
        $user->setCreatedAt($created);
        $user->setUpdatedAt($updated);
        $user->setLastLoginAt($lastLogin);

        self::assertSame($created, $user->getCreatedAt());
        self::assertSame($updated, $user->getUpdatedAt());
        self::assertSame($lastLogin, $user->getLastLoginAt());
    }

    public function testSsoFields(): void
    {
        $user = new User();
        $user->setSsoProvider('google');
        $user->setSsoId('sso-123');

        self::assertSame('google', $user->getSsoProvider());
        self::assertSame('sso-123', $user->getSsoId());
    }

    public function testOrganizationIdStringCast(): void
    {
        $user = new User();
        $user->setOrganizationId(42);

        self::assertSame('42', $user->getOrganizationId());
    }

    public function testOrganizationIdNull(): void
    {
        $user = new User();
        $user->setOrganizationId(null);

        self::assertNull($user->getOrganizationId());
    }

    public function testProfile(): void
    {
        $profile = $this->createMock(UserProfile::class);
        $user = new User();
        $user->setProfile($profile);

        self::assertSame($profile, $user->getProfile());
    }

    public function testEraseCredentialsDoesNothing(): void
    {
        $user = new User();
        $user->eraseCredentials();
        $this->addToAssertionCount(1);
    }
}
