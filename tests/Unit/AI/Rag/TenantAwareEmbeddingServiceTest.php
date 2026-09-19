<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Rag;

use App\AI\Platform\TenantPlatformContext;
use App\AI\Rag\EmbeddingServiceInterface;
use App\AI\Rag\TenantAwareEmbeddingService;
use App\Service\SecretService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Unit-Tests fuer TenantAwareEmbeddingService (pro-Tenant API-Key fuer
 * RAG-Embeddings aus dem SecretService, analog TenantAwarePlatform).
 *
 * Der Tenant-Pfad baut intern eine echte MistralEmbeddingService-Instanz;
 * deterministisch pruefbar sind die Delegations-Pfade (kein Tenant, Tenant
 * ohne Secret) sowie Dimension/Modell-Delegation.
 */
final class TenantAwareEmbeddingServiceTest extends TestCase
{
    private EmbeddingServiceInterface&MockObject $defaultService;
    private SecretService&MockObject $secretService;
    private TenantPlatformContext $tenantContext;
    private TenantAwareEmbeddingService $service;

    protected function setUp(): void
    {
        $this->defaultService = $this->createMock(EmbeddingServiceInterface::class);
        $this->secretService = $this->createMock(SecretService::class);
        $this->tenantContext = new TenantPlatformContext();
        $this->service = new TenantAwareEmbeddingService(
            $this->defaultService,
            $this->secretService,
            $this->tenantContext,
            $this->createMock(HttpClientInterface::class)
        );
    }

    public function testEmbedTextDelegatesToDefaultWhenNoTenantContext(): void
    {
        $this->defaultService
            ->expects(self::once())
            ->method('embedText')
            ->with('hallo welt')
            ->willReturn([0.1, 0.2]);
        $this->secretService
            ->expects(self::never())
            ->method('get');

        self::assertSame([0.1, 0.2], $this->service->embedText('hallo welt'));
    }

    public function testEmbedTextDelegatesToDefaultWhenTenantHasNoSecret(): void
    {
        $this->tenantContext->setUserIdentifier('tenant1');
        $this->secretService
            ->expects(self::once())
            ->method('get')
            ->with('MISTRAL_API_KEY', 'tenant1')
            ->willReturn(null);
        $this->defaultService
            ->expects(self::once())
            ->method('embedText')
            ->with('hallo welt')
            ->willReturn([0.3]);

        self::assertSame([0.3], $this->service->embedText('hallo welt'));
    }

    public function testEmbedTextDelegatesToDefaultWhenTenantSecretIsEmpty(): void
    {
        $this->tenantContext->setUserIdentifier('tenant2');
        $this->secretService
            ->expects(self::once())
            ->method('get')
            ->with('MISTRAL_API_KEY', 'tenant2')
            ->willReturn('');
        $this->defaultService
            ->expects(self::once())
            ->method('embedText')
            ->willReturn([]);

        self::assertSame([], $this->service->embedText('x'));
    }

    public function testEmbedTextBatchDelegatesToDefaultWithoutTenant(): void
    {
        $this->defaultService
            ->expects(self::once())
            ->method('embedTextBatch')
            ->with(['a', 'b'])
            ->willReturn([[0.1], [0.2]]);

        self::assertSame([[0.1], [0.2]], $this->service->embedTextBatch(['a', 'b']));
    }

    public function testGetDimensionAndModelNameDelegateToDefault(): void
    {
        $this->defaultService
            ->method('getDimension')
            ->willReturn(1024);
        $this->defaultService
            ->method('getModelName')
            ->willReturn('mistral-embed');

        self::assertSame(1024, $this->service->getDimension());
        self::assertSame('mistral-embed', $this->service->getModelName());
    }

    public function testSecretIsResolvedOnlyOncePerTenant(): void
    {
        $this->tenantContext->setUserIdentifier('tenant3');
        $this->secretService
            ->expects(self::once())
            ->method('get')
            ->with('MISTRAL_API_KEY', 'tenant3')
            ->willReturn('tenant-key');
        $this->defaultService
            ->expects(self::never())
            ->method('embedText');

        $vector = $this->service->embedText('erste anfrage');
        $second = $this->service->embedText('zweite anfrage');

        self::assertNotNull($vector);
        self::assertNotNull($second);
    }
}
