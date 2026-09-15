<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Platform;

use App\AI\Platform\TenantAwarePlatform;
use App\AI\Platform\TenantPlatformContext;
use App\Service\SecretService;
use App\Tests\Stub\StubDeferredResult;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Unit-Tests fuer TenantAwarePlatform (pro-Tenant API-Key-Aufloesung aus
 * dem SecretService). Die statische MistralFactory kann im Unit-Test nicht
 * gemockt werden; daher pruefen wir die Default-Delegations-Pfade (kein
 * Tenant, Tenant ohne Secret) deterministisch.
 */
final class TenantAwarePlatformTest extends TestCase
{
    private PlatformInterface&MockObject $defaultPlatform;
    private SecretService&MockObject $secretService;
    private TenantPlatformContext $tenantContext;
    private HttpClientInterface&MockObject $httpClient;
    private TenantAwarePlatform $platform;

    protected function setUp(): void
    {
        $this->defaultPlatform = $this->createMock(PlatformInterface::class);
        $this->secretService = $this->createMock(SecretService::class);
        $this->tenantContext = new TenantPlatformContext();
        $this->httpClient = $this->createMock(HttpClientInterface::class);

        $this->platform = new TenantAwarePlatform(
            $this->defaultPlatform,
            $this->secretService,
            $this->tenantContext,
            $this->httpClient,
            new NullLogger()
        );
    }

    private function createDeferredResult(): DeferredResult
    {
        return StubDeferredResult::withText('ok');
    }

    public function testInvokeDelegatesToDefaultWhenNoTenantContext(): void
    {
        $deferred = $this->createDeferredResult();
        $this->defaultPlatform
            ->expects(self::once())
            ->method('invoke')
            ->with('mistral-small-latest', 'hello')
            ->willReturn($deferred);

        $this->secretService
            ->expects(self::never())
            ->method('get');

        $result = $this->platform->invoke('mistral-small-latest', 'hello');

        self::assertSame($deferred, $result);
    }

    public function testInvokeDelegatesToDefaultWhenTenantHasNoSecret(): void
    {
        $this->tenantContext->setUserIdentifier('tenant1');

        $this->secretService
            ->expects(self::once())
            ->method('get')
            ->with('MISTRAL_API_KEY', 'tenant1')
            ->willReturn(null);

        $deferred = $this->createDeferredResult();
        $this->defaultPlatform
            ->expects(self::once())
            ->method('invoke')
            ->with('mistral-small-latest', 'hello')
            ->willReturn($deferred);

        $result = $this->platform->invoke('mistral-small-latest', 'hello');

        self::assertSame($deferred, $result);
    }

    public function testInvokeDelegatesToDefaultWhenTenantSecretIsEmpty(): void
    {
        $this->tenantContext->setUserIdentifier('tenant1');

        $this->secretService
            ->expects(self::once())
            ->method('get')
            ->with('MISTRAL_API_KEY', 'tenant1')
            ->willReturn('');

        $deferred = $this->createDeferredResult();
        $this->defaultPlatform
            ->expects(self::once())
            ->method('invoke')
            ->willReturn($deferred);

        $result = $this->platform->invoke('mistral-small-latest', 'hello');

        self::assertSame($deferred, $result);
    }

    public function testGetModelCatalogDelegatesToDefaultWhenNoTenant(): void
    {
        $catalog = $this->createMock(ModelCatalogInterface::class);
        $this->defaultPlatform
            ->expects(self::once())
            ->method('getModelCatalog')
            ->willReturn($catalog);

        self::assertSame($catalog, $this->platform->getModelCatalog());
    }

    public function testGetModelCatalogDelegatesToDefaultWhenNoSecret(): void
    {
        $this->tenantContext->setUserIdentifier('tenant1');

        $this->secretService
            ->expects(self::once())
            ->method('get')
            ->willReturn(null);

        $catalog = $this->createMock(ModelCatalogInterface::class);
        $this->defaultPlatform
            ->expects(self::once())
            ->method('getModelCatalog')
            ->willReturn($catalog);

        self::assertSame($catalog, $this->platform->getModelCatalog());
    }

    public function testTenantContextClearResetsToDefault(): void
    {
        $this->tenantContext->setUserIdentifier('tenant1');
        $this->secretService->method('get')->willReturn(null);
        $this->defaultPlatform->method('invoke')->willReturn($this->createDeferredResult());

        $this->platform->invoke('mistral-small-latest', 'first');

        $this->tenantContext->clear();

        $this->defaultPlatform
            ->expects(self::once())
            ->method('invoke')
            ->willReturn($this->createDeferredResult());

        $this->platform->invoke('mistral-small-latest', 'second');
    }
}
