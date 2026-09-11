<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Secret;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Unit-Tests fuer Secret-Entity.
 */
final class SecretTest extends TestCase
{
    public function testGettersAndSetters(): void
    {
        $secret = new Secret();
        $created = new DateTimeImmutable('2025-01-01');
        $updated = new DateTimeImmutable('2025-01-02');
        $lastUsed = new DateTimeImmutable('2025-01-03');

        $secret
            ->setUserIdentifier('user@example.com')
            ->setKeyName('API_KEY')
            ->setEncryptedValue('encrypted')
            ->setScope('global')
            ->setCreatedAt($created)
            ->setUpdatedAt($updated)
            ->setLastUsedAt($lastUsed)
            ->setLastUsedByTool('weather');

        self::assertSame('user@example.com', $secret->getUserIdentifier());
        self::assertSame('API_KEY', $secret->getKeyName());
        self::assertSame('encrypted', $secret->getEncryptedValue());
        self::assertSame('global', $secret->getScope());
        self::assertSame($created, $secret->getCreatedAt());
        self::assertSame($updated, $secret->getUpdatedAt());
        self::assertSame($lastUsed, $secret->getLastUsedAt());
        self::assertSame('weather', $secret->getLastUsedByTool());
    }

    public function testDefaults(): void
    {
        $secret = new Secret();
        self::assertNull($secret->getId());
        self::assertNull($secret->getScope());
        self::assertNull($secret->getUpdatedAt());
        self::assertNull($secret->getLastUsedAt());
        self::assertNull($secret->getLastUsedByTool());
        self::assertInstanceOf(DateTimeImmutable::class, $secret->getCreatedAt());
    }

    public function testUpdateLastUsed(): void
    {
        $secret = new Secret();
        $secret->setKeyName('K');
        $secret->updateLastUsed('myTool');

        self::assertInstanceOf(DateTimeImmutable::class, $secret->getLastUsedAt());
        self::assertSame('myTool', $secret->getLastUsedByTool());
    }

    public function testNullableScope(): void
    {
        $secret = new Secret();
        $secret->setScope(null);
        self::assertNull($secret->getScope());
    }
}
