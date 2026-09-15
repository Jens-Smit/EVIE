<?php

declare(strict_types=1);

namespace App\AI\Platform;

use App\Service\SecretService;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Bridge\Mistral\Factory as MistralFactory;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Dekoriert die Symfony-AI-Mistral-Platform pro Tenant: lies den
 * provider-spezifischen API-Key aus dem SecretService (DB, verschluesselt)
 * und baue dafuer eine eigene Platform-Instanz ueber die offizielle
 * Symfony\AI\Platform\Bridge\Mistral\Factory::createPlatform($apiKey).
 *
 * Ohne Tenant-Secret (z.B. Onboarding, System-Requests, CI mit env-Key) wird
 * transparent an die Default-Platform (env-basierter Key aus ai.yaml)
 * delegiert. Das schliesst die Luecke, dass der im Onboarding/Frontend
 * hinterlegte per-Tenant-Key vorher ignoriert wurde.
 *
 * Symfony-AI-kompatibel: keine eigene Bridge, kein Vendor-Patch, keine
 * Konstruktor-Injection fuer Tools. Der Decorator implementiert
 * PlatformInterface 1:1 und nutzt ausschliesslich die oeffentliche Factory.
 * Analog zum bestehenden QuotaDecorator.
 */
final class TenantAwarePlatform implements PlatformInterface
{
    private const SECRET_KEY_MISTRAL = 'MISTRAL_API_KEY';

    /** @var array<string, PlatformInterface> Cache pro userIdentifier */
    private array $tenantPlatforms = [];

    public function __construct(
        private readonly PlatformInterface $defaultPlatform,
        private readonly SecretService $secretService,
        private readonly TenantPlatformContext $tenantContext,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function invoke(Model|string $model, object|array|string $input, array $options = []): DeferredResult
    {
        return $this->resolvePlatform()->invoke($model, $input, $options);
    }

    public function getModelCatalog(): ModelCatalogInterface
    {
        return $this->resolvePlatform()->getModelCatalog();
    }

    /**
     * Liefert die pro-Tenant-Platform (aus DB-Secret) oder die Default-
     * Platform (env-Key), falls kein Tenant-Secret existiert.
     */
    private function resolvePlatform(): PlatformInterface
    {
        $userIdentifier = $this->tenantContext->getUserIdentifier();
        if ($userIdentifier === null || $userIdentifier === '') {
            return $this->defaultPlatform;
        }

        if (isset($this->tenantPlatforms[$userIdentifier])) {
            return $this->tenantPlatforms[$userIdentifier];
        }

        $apiKey = $this->secretService->get(self::SECRET_KEY_MISTRAL, $userIdentifier);
        if ($apiKey === null || $apiKey === '') {
            // Kein Tenant-Secret -> Default-Platform (env-Key) nutzen.
            return $this->defaultPlatform;
        }

        $platform = MistralFactory::createPlatform($apiKey, $this->httpClient);
        $this->tenantPlatforms[$userIdentifier] = $platform;
        $this->logger->debug('TenantAwarePlatform: pro-Tenant-Platform aus DB-Secret erzeugt', [
            'user_identifier' => $userIdentifier,
        ]);

        return $platform;
    }
}
