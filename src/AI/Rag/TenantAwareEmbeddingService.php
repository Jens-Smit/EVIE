<?php

declare(strict_types=1);

namespace App\AI\Rag;

use App\AI\Platform\TenantPlatformContext;
use App\Service\SecretService;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Dekoriert die Embedding-Erzeugung pro Tenant: lies den Mistral-API-Key
 * aus dem SecretService (DB, verschluesselt, im Onboarding hinterlegt) und
 * baue dafuer eine eigene MistralEmbeddingService-Instanz. Ohne Tenant-
 * Secret (System-Requests, CI mit env-Key) wird transparent an die
 * Default-Instanz (env-basierter Key) delegiert.
 *
 * Analog zu App\AI\Platform\TenantAwarePlatform: der pro-Tenant-Key, den
 * der Nutzer im Onboarding hinterlegt hat, wird dadurch auch fuer RAG-
 * Embeddings genutzt, nicht nur fuer Chat/LLM-Calls.
 *
 * Der Tenant-Identifier kommt ausschliesslich aus dem authentifizierten
 * Kontext (TenantPlatformContext), nie aus Request-Daten (P0-5).
 */
final class TenantAwareEmbeddingService implements EmbeddingServiceInterface
{
    private const SECRET_KEY_MISTRAL = 'MISTRAL_API_KEY';

    /** @var array<string, EmbeddingServiceInterface> Cache pro userIdentifier */
    private array $tenantServices = [];

    public function __construct(
        private readonly EmbeddingServiceInterface $defaultService,
        private readonly SecretService $secretService,
        private readonly TenantPlatformContext $tenantContext,
        private readonly HttpClientInterface $httpClient,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function embedText(string $text): array
    {
        return $this->resolve()->embedText($text);
    }

    public function embedTextBatch(array $texts): array
    {
        return $this->resolve()->embedTextBatch($texts);
    }

    public function getDimension(): int
    {
        return $this->defaultService->getDimension();
    }

    public function getModelName(): string
    {
        return $this->defaultService->getModelName();
    }

    /**
     * Liefert die pro-Tenant-Embedding-Instanz (aus DB-Secret) oder die
     * Default-Instanz (env-Key), falls kein Tenant-Secret existiert.
     */
    private function resolve(): EmbeddingServiceInterface
    {
        $userIdentifier = $this->tenantContext->getUserIdentifier();
        if ($userIdentifier === null || $userIdentifier === '') {
            return $this->defaultService;
        }

        if (isset($this->tenantServices[$userIdentifier])) {
            return $this->tenantServices[$userIdentifier];
        }

        $apiKey = $this->secretService->get(self::SECRET_KEY_MISTRAL, $userIdentifier);
        if ($apiKey === null || $apiKey === '') {
            return $this->defaultService;
        }

        $service = new MistralEmbeddingService($this->httpClient, $apiKey, $this->logger);
        $this->tenantServices[$userIdentifier] = $service;
        $this->logger?->debug('TenantAwareEmbeddingService: pro-Tenant-Embedding-Instanz aus DB-Secret erzeugt', [
            'user_identifier' => $userIdentifier,
        ]);

        return $service;
    }
}
