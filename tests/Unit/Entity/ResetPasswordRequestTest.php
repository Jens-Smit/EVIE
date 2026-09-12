<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\ResetPasswordRequest;
use App\Entity\User;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Unit-Tests fuer ResetPasswordRequest-Entity.
 */
final class ResetPasswordRequestTest extends TestCase
{
    public function testGettersAndSetters(): void
    {
        $request = new ResetPasswordRequest();
        $user = $this->createMock(User::class);
        $requested = new DateTimeImmutable('2025-01-01');
        $expires = new DateTimeImmutable('2099-01-01');

        $request
            ->setUser($user)
            ->setSelector('selector')
            ->setHashedToken('hashed')
            ->setRequestedAt($requested)
            ->setExpiresAt($expires);

        self::assertSame($user, $request->getUser());
        self::assertSame('selector', $request->getSelector());
        self::assertSame('hashed', $request->getHashedToken());
        self::assertSame($requested, $request->getRequestedAt());
        self::assertSame($expires, $request->getExpiresAt());
    }

    public function testDefaults(): void
    {
        $request = new ResetPasswordRequest();
        self::assertNull($request->getId());
        self::assertNull($request->getUser());
        self::assertNull($request->getSelector());
        self::assertNull($request->getHashedToken());
        self::assertInstanceOf(DateTimeImmutable::class, $request->getRequestedAt());
        self::assertNull($request->getExpiresAt());
    }

    public function testIsExpiredWhenExpiresInPast(): void
    {
        $request = new ResetPasswordRequest();
        $request->setExpiresAt(new DateTimeImmutable('2020-01-01'));

        self::assertTrue($request->isExpired());
    }

    public function testIsExpiredWhenExpiresInFuture(): void
    {
        $request = new ResetPasswordRequest();
        $request->setExpiresAt(new DateTimeImmutable('2099-01-01'));

        self::assertFalse($request->isExpired());
    }
}
