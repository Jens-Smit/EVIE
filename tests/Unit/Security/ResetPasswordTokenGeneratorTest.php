<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\ResetPasswordRequest;
use App\Entity\User;
use App\Repository\ResetPasswordRequestRepository;
use App\Security\ResetPasswordTokenGenerator;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit-Tests fuer ResetPasswordTokenGenerator::validate() — deckt alle
 * null-Rueckgabe-Pfade (zu kurz, nicht gefunden, abgelaufen, Hash-Mismatch)
 * sowie den positiven Pfad ab.
 */
final class ResetPasswordTokenGeneratorTest extends TestCase
{
    public function testValidateReturnsNullForTooShortToken(): void
    {
        $repo = $this->createMock(ResetPasswordRequestRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $gen = new ResetPasswordTokenGenerator($repo, $em);

        self::assertNull($gen->validate('short'));
    }

    public function testValidateReturnsNullWhenSelectorNotFound(): void
    {
        $repo = $this->createMock(ResetPasswordRequestRepository::class);
        $repo->method('findBySelector')->willReturn(null);
        $em = $this->createMock(EntityManagerInterface::class);
        $gen = new ResetPasswordTokenGenerator($repo, $em);

        $selector = str_repeat('a', 32);
        $verifier = str_repeat('b', 32);
        self::assertNull($gen->validate($selector . $verifier));
    }

    public function testValidateReturnsNullWhenExpired(): void
    {
        $request = (new ResetPasswordRequest())
            ->setHashedToken(hash('sha256', str_repeat('b', 32)))
            ->setExpiresAt(new DateTimeImmutable('-1 hour'));

        $repo = $this->createMock(ResetPasswordRequestRepository::class);
        $repo->method('findBySelector')->willReturn($request);
        $em = $this->createMock(EntityManagerInterface::class);
        $gen = new ResetPasswordTokenGenerator($repo, $em);

        $selector = str_repeat('a', 32);
        $verifier = str_repeat('b', 32);
        self::assertNull($gen->validate($selector . $verifier));
    }

    public function testValidateReturnsNullForHashMismatch(): void
    {
        $request = (new ResetPasswordRequest())
            ->setHashedToken(hash('sha256', 'different'))
            ->setExpiresAt(new DateTimeImmutable('+1 hour'));

        $repo = $this->createMock(ResetPasswordRequestRepository::class);
        $repo->method('findBySelector')->willReturn($request);
        $em = $this->createMock(EntityManagerInterface::class);
        $gen = new ResetPasswordTokenGenerator($repo, $em);

        $selector = str_repeat('a', 32);
        $verifier = str_repeat('b', 32);
        self::assertNull($gen->validate($selector . $verifier));
    }

    public function testValidateReturnsRequestForValidToken(): void
    {
        $verifier = str_repeat('b', 32);
        $request = (new ResetPasswordRequest())
            ->setHashedToken(hash('sha256', $verifier))
            ->setExpiresAt(new DateTimeImmutable('+1 hour'));

        $repo = $this->createMock(ResetPasswordRequestRepository::class);
        $repo->method('findBySelector')->willReturn($request);
        $em = $this->createMock(EntityManagerInterface::class);
        $gen = new ResetPasswordTokenGenerator($repo, $em);

        $selector = str_repeat('a', 32);
        self::assertSame($request, $gen->validate($selector . $verifier));
    }

    public function testConsumeRemovesRequest(): void
    {
        $request = (new ResetPasswordRequest());
        $repo = $this->createMock(ResetPasswordRequestRepository::class);
        $repo->expects(self::once())->method('remove')->with($request, true);
        $em = $this->createMock(EntityManagerInterface::class);
        $gen = new ResetPasswordTokenGenerator($repo, $em);

        $gen->consume($request);
    }

    public function testGenerateProducesTokenAndPersistsRequest(): void
    {
        $user = (new User())->setEmail('u@t.de');
        $repo = $this->createMock(ResetPasswordRequestRepository::class);
        $repo->expects(self::once())->method('removeForUser')->with($user);
        $repo->expects(self::once())->method('save');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');
        $gen = new ResetPasswordTokenGenerator($repo, $em);

        $token = $gen->generate($user);
        self::assertSame(64, strlen($token));
    }
}
