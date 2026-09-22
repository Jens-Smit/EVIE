<?php

declare(strict_types=1);

// tests/Unit/Security/UserProviderTest.php

namespace App\Tests\Unit\Security;

use App\Entity\User;
use App\Security\UserProvider;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;

final class UserProviderTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;
    private EntityRepository&MockObject $repository;
    private UserProvider $provider;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(EntityRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->entityManager->method('getRepository')->with(User::class)->willReturn($this->repository);
        $this->provider = new UserProvider($this->entityManager);
    }

    public function testLoadUserByIdentifierReturnsUser(): void
    {
        $user = new User();
        $user->setEmail('known@test.de');

        $this->repository->method('findOneBy')->with(['email' => 'known@test.de'])->willReturn($user);

        self::assertSame($user, $this->provider->loadUserByIdentifier('known@test.de'));
    }

    public function testLoadUserByIdentifierThrowsWhenUnknown(): void
    {
        $this->repository->method('findOneBy')->willReturn(null);

        $this->expectException(UserNotFoundException::class);
        $this->provider->loadUserByIdentifier('unknown@test.de');
    }

    public function testLoadUserByUsernameDelegatesToIdentifier(): void
    {
        $user = new User();
        $user->setEmail('delegate@test.de');

        $this->repository->method('findOneBy')->with(['email' => 'delegate@test.de'])->willReturn($user);

        self::assertSame($user, $this->provider->loadUserByUsername('delegate@test.de'));
    }

    public function testRefreshUserReturnsSameInstance(): void
    {
        $user = new User();
        self::assertSame($user, $this->provider->refreshUser($user));
    }

    public function testSupportsClassAcceptsUserAndSubclasses(): void
    {
        self::assertTrue($this->provider->supportsClass(User::class));
        self::assertFalse($this->provider->supportsClass(\stdClass::class));
    }
}
