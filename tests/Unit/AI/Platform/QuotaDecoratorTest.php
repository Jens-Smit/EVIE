<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Platform;

use App\AI\Platform\QuotaDecorator;
use App\AI\Security\AuditLogger;
use App\Entity\AuditLog;
use App\Repository\AuditLogRepository;
use App\Repository\TenantQuotaRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\ResultConverterInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Unit-Tests fuer QuotaDecorator (Token-Quota-Durchsetzung pro Tenant).
 */
final class QuotaDecoratorTest extends TestCase
{
    private PlatformInterface&MockObject $innerPlatform;
    private TenantQuotaRepository&MockObject $quotaRepo;
    private AuditLogger $auditLogger;
    private QuotaDecorator $decorator;

    protected function setUp(): void
    {
        $this->innerPlatform = $this->createMock(PlatformInterface::class);
        $this->quotaRepo = $this->createMock(TenantQuotaRepository::class);

        $auditRepo = $this->createMock(AuditLogRepository::class);
        $auditRepo->method('log')->willReturn(new AuditLog());
        $this->auditLogger = new AuditLogger($auditRepo, new RequestStack());

        $this->decorator = new QuotaDecorator(
            $this->innerPlatform,
            $this->quotaRepo,
            $this->auditLogger,
            new NullLogger()
        );
    }

    private function createTestUser(string $identifier): UserInterface
    {
        return new class($identifier) implements UserInterface {
            public function __construct(private string $id) {}

            public function getId(): ?int
            {
                return 1;
            }

            public function getUserIdentifier(): string
            {
                return $this->id;
            }

            public function getRoles(): array
            {
                return ['ROLE_USER'];
            }

            public function eraseCredentials(): void {}
        };
    }

    private function createDeferredResult(): DeferredResult
    {
        $converter = $this->createMock(ResultConverterInterface::class);
        $rawResult = $this->createMock(RawResultInterface::class);
        return new DeferredResult($converter, $rawResult);
    }

    public function testSetUserReturnsStatic(): void
    {
        $user = $this->createTestUser('tenant1');
        $result = $this->decorator->setUser($user);
        self::assertSame($this->decorator, $result);
    }

    public function testGetInnerPlatform(): void
    {
        self::assertSame($this->innerPlatform, $this->decorator->getInnerPlatform());
    }

    public function testInvokeWithoutUserAlwaysAllowed(): void
    {
        $deferred = $this->createDeferredResult();

        $this->innerPlatform
            ->expects(self::once())
            ->method('invoke')
            ->willReturn($deferred);

        $this->quotaRepo->expects(self::never())->method('isQuotaExceeded');
        $this->quotaRepo->expects(self::never())->method('recordTokenUsage');
        $this->quotaRepo->expects(self::never())->method('recordRequestUsage');

        $result = $this->decorator->invoke('mistral-small', 'test input');

        self::assertSame($deferred, $result);
    }

    public function testInvokeWithUserWithinQuota(): void
    {
        $user = $this->createTestUser('tenant1');
        $this->decorator->setUser($user);

        $deferred = $this->createDeferredResult();

        $this->quotaRepo->method('isQuotaExceeded')->with('tenant1')->willReturn(false);
        $this->quotaRepo->expects(self::once())->method('recordRequestUsage')->with('tenant1');
        $this->quotaRepo->expects(self::once())->method('recordTokenUsage')->with('tenant1', self::greaterThan(0));

        $this->innerPlatform
            ->expects(self::once())
            ->method('invoke')
            ->willReturn($deferred);

        $result = $this->decorator->invoke('mistral-small', 'test input');

        self::assertSame($deferred, $result);
    }

    public function testInvokeWithUserQuotaExceededThrowsException(): void
    {
        $user = $this->createTestUser('tenant1');
        $this->decorator->setUser($user);

        $this->quotaRepo->method('isQuotaExceeded')->with('tenant1')->willReturn(true);
        $this->quotaRepo->method('getQuotaUsage')->willReturn([
            'max_tokens_per_day' => 1000,
            'current_day_usage' => 1000,
            'max_requests_per_hour' => 100,
            'current_hour_usage' => 100,
        ]);

        $this->innerPlatform->expects(self::never())->method('invoke');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Token-Quota');

        $this->decorator->invoke('mistral-small', 'test');
    }

    public function testInvokeWithArrayInputCalculatesTokenUsage(): void
    {
        $user = $this->createTestUser('tenant1');
        $this->decorator->setUser($user);

        $deferred = $this->createDeferredResult();

        $this->quotaRepo->method('isQuotaExceeded')->willReturn(false);
        $this->quotaRepo
            ->expects(self::once())
            ->method('recordTokenUsage')
            ->willReturnCallback(function (string $user, int $tokens): void {
                self::assertGreaterThan(0, $tokens);
            });

        $this->innerPlatform->method('invoke')->willReturn($deferred);

        $this->decorator->invoke('mistral-small', ['key' => 'value']);
    }

    public function testInvokeWithObjectInputRecordsZeroTokens(): void
    {
        $user = $this->createTestUser('tenant1');
        $this->decorator->setUser($user);

        $deferred = $this->createDeferredResult();

        $this->quotaRepo->method('isQuotaExceeded')->willReturn(false);
        $this->quotaRepo
            ->expects(self::once())
            ->method('recordTokenUsage')
            ->with('tenant1', 0);

        $this->innerPlatform->method('invoke')->willReturn($deferred);

        $this->decorator->invoke('mistral-small', new \stdClass());
    }

    public function testGetModelCatalogDelegatesToInnerPlatform(): void
    {
        $catalog = $this->createMock(ModelCatalogInterface::class);

        $this->innerPlatform
            ->expects(self::once())
            ->method('getModelCatalog')
            ->willReturn($catalog);

        self::assertSame($catalog, $this->decorator->getModelCatalog());
    }
}
