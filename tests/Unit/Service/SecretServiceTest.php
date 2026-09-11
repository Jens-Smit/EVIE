<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Secret;
use App\Repository\SecretRepository;
use App\Service\SecretService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit-Tests für SecretService (AES-256-GCM Verschlüsselung pro Tenant).
 */
final class SecretServiceTest extends TestCase
{
    private SecretRepository&MockObject $secretRepository;
    private EntityManagerInterface&MockObject $entityManager;
    private SecretService $service;

    protected function setUp(): void
    {
        $this->secretRepository = $this->createMock(SecretRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->service = new SecretService(
            $this->secretRepository,
            $this->entityManager,
            'test-master-secret-key-for-encryption-1234567890'
        );
    }

    public function testSetCreatesNewSecretWhenNotExisting(): void
    {
        $this->secretRepository
            ->expects(self::once())
            ->method('findOneByKeyAndUser')
            ->with('api_key', 'tenant1')
            ->willReturn(null);

        $this->secretRepository
            ->expects(self::once())
            ->method('save')
            ->willReturnCallback(function (Secret $secret, bool $flush): void {
                self::assertSame('tenant1', $secret->getUserIdentifier());
                self::assertSame('api_key', $secret->getKeyName());
                self::assertSame('scope1', $secret->getScope());
                self::assertNotSame('secret-value', $secret->getEncryptedValue());
                self::assertTrue($flush);
            });

        $this->service->set('api_key', 'secret-value', 'tenant1', 'scope1');
    }

    public function testSetUpdatesExistingSecret(): void
    {
        $existing = new Secret();
        $existing->setUserIdentifier('tenant1')
            ->setKeyName('api_key')
            ->setEncryptedValue('old-encrypted')
            ->setScope('old-scope');

        $this->secretRepository
            ->expects(self::once())
            ->method('findOneByKeyAndUser')
            ->with('api_key', 'tenant1')
            ->willReturn($existing);

        $this->secretRepository
            ->expects(self::once())
            ->method('save')
            ->willReturnCallback(function (Secret $secret, bool $flush) use ($existing): void {
                self::assertSame($existing, $secret);
                self::assertSame('new-scope', $secret->getScope());
                self::assertNotSame('old-encrypted', $secret->getEncryptedValue());
                self::assertNotNull($secret->getUpdatedAt());
                self::assertTrue($flush);
            });

        $this->service->set('api_key', 'new-value', 'tenant1', 'new-scope');
    }

    public function testSetThrowsOnEmptyKeyName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->set('', 'value', 'tenant1');
    }

    public function testSetThrowsOnEmptyValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->set('key', '', 'tenant1');
    }

    public function testSetThrowsOnEmptyUserIdentifier(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->set('key', 'value', '');
    }

    public function testGetReturnsDecryptedValue(): void
    {
        $this->service->set('test_key', 'my-secret-value', 'tenant1');

        $secret = new Secret();
        $secret->setUserIdentifier('tenant1')
            ->setKeyName('test_key')
            ->setEncryptedValue('');

        $this->secretRepository
            ->method('findOneByKeyAndUser')
            ->willReturnCallback(function (string $keyName) {
                $secret = new Secret();
                $secret->setKeyName($keyName);
                $secret->setEncryptedValue('');

                $reflection = new \ReflectionClass(Secret::class);
                $prop = $reflection->getProperty('encryptedValue');
                $prop->setValue($secret, $this->encryptValue($secret, 'my-secret-value'));

                return $secret;
            });

        $result = $this->service->get('test_key', 'tenant1');
        self::assertSame('my-secret-value', $result);
    }

    public function testGetReturnsNullWhenNotFound(): void
    {
        $this->secretRepository
            ->method('findOneByKeyAndUser')
            ->willReturn(null);

        self::assertNull($this->service->get('nonexistent', 'tenant1'));
    }

    public function testDeleteRemovesSecret(): void
    {
        $secret = new Secret();
        $secret->setKeyName('api_key');

        $this->secretRepository
            ->expects(self::once())
            ->method('findOneByKeyAndUser')
            ->with('api_key', 'tenant1')
            ->willReturn($secret);

        $this->secretRepository
            ->expects(self::once())
            ->method('remove')
            ->with($secret, true);

        $this->service->delete('api_key', 'tenant1');
    }

    public function testDeleteDoesNothingWhenNotFound(): void
    {
        $this->secretRepository
            ->method('findOneByKeyAndUser')
            ->willReturn(null);

        $this->secretRepository
            ->expects(self::never())
            ->method('remove');

        $this->service->delete('api_key', 'tenant1');
    }

    public function testExistsReturnsTrueWhenSecretFound(): void
    {
        $secret = new Secret();

        $this->secretRepository
            ->method('findOneByKeyAndUser')
            ->willReturn($secret);

        self::assertTrue($this->service->exists('api_key', 'tenant1'));
    }

    public function testExistsReturnsFalseWhenNotFound(): void
    {
        $this->secretRepository
            ->method('findOneByKeyAndUser')
            ->willReturn(null);

        self::assertFalse($this->service->exists('api_key', 'tenant1'));
    }

    public function testGetKeysForUserReturnsKeyNames(): void
    {
        $secret1 = new Secret();
        $secret1->setKeyName('key1');
        $secret2 = new Secret();
        $secret2->setKeyName('key2');

        $this->secretRepository
            ->method('findByUser')
            ->with('tenant1')
            ->willReturn([$secret1, $secret2]);

        $result = $this->service->getKeysForUser('tenant1');
        self::assertSame(['key1', 'key2'], $result);
    }

    public function testGetAllForUserReturnsDecryptedMap(): void
    {
        $secret1 = new Secret();
        $secret1->setKeyName('key1')->setEncryptedValue($this->encryptForTest('value1'));
        $secret2 = new Secret();
        $secret2->setKeyName('key2')->setEncryptedValue($this->encryptForTest('value2'));

        $this->secretRepository
            ->method('findByUser')
            ->with('tenant1')
            ->willReturn([$secret1, $secret2]);

        $result = $this->service->getAllForUser('tenant1');
        self::assertSame(['key1' => 'value1', 'key2' => 'value2'], $result);
    }

    public function testDeleteAllForUserRemovesAllAndFlushes(): void
    {
        $secret1 = new Secret();
        $secret2 = new Secret();

        $this->secretRepository
            ->method('findByUser')
            ->with('tenant1')
            ->willReturn([$secret1, $secret2]);

        $this->secretRepository
            ->expects(self::exactly(2))
            ->method('remove')
            ->with(self::logicalOr($secret1, $secret2), false);

        $this->entityManager
            ->expects(self::once())
            ->method('flush');

        $this->service->deleteAllForUser('tenant1');
    }

    public function testUpdateLastUsedUpdatesSecret(): void
    {
        $secret = new Secret();
        $secret->setKeyName('api_key');

        $this->secretRepository
            ->expects(self::once())
            ->method('findOneByKeyAndUser')
            ->willReturn($secret);

        $this->secretRepository
            ->expects(self::once())
            ->method('updateLastUsed')
            ->with($secret, 'MyTool');

        $this->service->updateLastUsed('api_key', 'tenant1', 'MyTool');
    }

    public function testUpdateLastUsedDoesNothingWhenNotFound(): void
    {
        $this->secretRepository
            ->method('findOneByKeyAndUser')
            ->willReturn(null);

        $this->secretRepository
            ->expects(self::never())
            ->method('updateLastUsed');

        $this->service->updateLastUsed('api_key', 'tenant1', 'MyTool');
    }

    public function testEncryptDecryptRoundtrip(): void
    {
        $this->service->set('roundtrip_key', 'roundtrip-value', 'tenant1');

        $secret = new Secret();
        $secret->setKeyName('roundtrip_key');

        $reflection = new \ReflectionClass(SecretService::class);
        $encryptMethod = $reflection->getMethod('encrypt');
        $encrypted = $encryptMethod->invoke($this->service, 'roundtrip-value');

        $secret->setEncryptedValue($encrypted);

        $this->secretRepository
            ->method('findOneByKeyAndUser')
            ->willReturn($secret);

        self::assertSame('roundtrip-value', $this->service->get('roundtrip_key', 'tenant1'));
    }

    public function testDecryptThrowsOnInvalidBase64(): void
    {
        $secret = new Secret();
        $secret->setKeyName('bad')->setEncryptedValue(base64_encode('short'));

        $this->secretRepository
            ->method('findOneByKeyAndUser')
            ->willReturn($secret);

        $this->expectException(\RuntimeException::class);
        $this->service->get('bad', 'tenant1');
    }

    private function encryptForTest(string $value): string
    {
        $reflection = new \ReflectionClass(SecretService::class);
        $method = $reflection->getMethod('encrypt');
        return $method->invoke($this->service, $value);
    }

    private function encryptValue(Secret $secret, string $value): string
    {
        return $this->encryptForTest($value);
    }
}
