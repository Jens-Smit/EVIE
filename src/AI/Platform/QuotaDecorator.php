<?php

namespace App\AI\Platform;

use App\AI\Security\AuditLogger;
use App\Entity\User;
use App\Repository\TenantQuotaRepository;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Decorator für PlatformInterface, der Token-Quotas pro Tenant durchsetzt.
 * 
 * Dieser Decorator umschließt ein PlatformInterface und prüft vor jedem
 * Aufruf, ob der Tenant sein Quota überschritten hat.
 */
class QuotaDecorator implements PlatformInterface
{
    private ?UserInterface $user = null;

    public function __construct(
        private PlatformInterface $innerPlatform,
        private TenantQuotaRepository $quotaRepo,
        private AuditLogger $auditLogger,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Setze den aktuellen Benutzer für Quota-Prüfungen
     */
    public function setUser(UserInterface $user): static
    {
        $this->user = $user;
        return $this;
    }

    /**
     * Get the inner platform
     */
    public function getInnerPlatform(): PlatformInterface
    {
        return $this->innerPlatform;
    }

    /**
     * Prüfe ob das Quota überschritten ist
     */
    private function checkQuota(): bool
    {
        if (null === $this->user) {
            // Kein Benutzer gesetzt - Quota-Prüfung überspringen
            return true;
        }

        $userIdentifier = $this->user->getUserIdentifier();
        
        if ($this->quotaRepo->isQuotaExceeded($userIdentifier)) {
            $quotaUsage = $this->quotaRepo->getQuotaUsage($userIdentifier);
            
            $this->logger->warning('Quota exceeded for user', [
                'user_identifier' => $userIdentifier,
                'current_day_usage' => $quotaUsage['current_day_usage'],
                'max_tokens_per_day' => $quotaUsage['max_tokens_per_day'],
                'current_hour_usage' => $quotaUsage['current_hour_usage'],
                'max_requests_per_hour' => $quotaUsage['max_requests_per_hour'],
            ]);

            // Audit-Log
            $this->auditLogger->log(
                'quota_exceeded',
                $this->user,
                null,
                'TenantQuota',
                [
                    'current_day_usage' => $quotaUsage['current_day_usage'],
                    'max_tokens_per_day' => $quotaUsage['max_tokens_per_day'],
                    'current_hour_usage' => $quotaUsage['current_hour_usage'],
                    'max_requests_per_hour' => $quotaUsage['max_requests_per_hour'],
                ],
                'failed',
                'Token-Quota überschritten'
            );

            return false;
        }

        return true;
    }

    /**
     * Record token usage
     */
    private function recordTokenUsage(int $tokens): void
    {
        if (null === $this->user) {
            return;
        }

        $userIdentifier = $this->user->getUserIdentifier();
        $this->quotaRepo->recordTokenUsage($userIdentifier, $tokens);
    }

    /**
     * Record request usage
     */
    private function recordRequestUsage(): void
    {
        if (null === $this->user) {
            return;
        }

        $userIdentifier = $this->user->getUserIdentifier();
        $this->quotaRepo->recordRequestUsage($userIdentifier);
    }

    /**
     * Get the name of the platform
     */
    public function getName(): string
    {
        return $this->innerPlatform->getName();
    }

    /**
     * @inheritDoc
     */
    public function getClient(): object
    {
        return $this->innerPlatform->getClient();
    }

    /**
     * @inheritDoc
     */
    public function getFeatures(): array
    {
        return $this->innerPlatform->getFeatures();
    }

    /**
     * @inheritDoc
     */
    public function hasFeature(string $feature): bool
    {
        return $this->innerPlatform->hasFeature($feature);
    }

    /**
     * @inheritDoc
     */
    public function getDefaultModel(): string
    {
        return $this->innerPlatform->getDefaultModel();
    }

    /**
     * @inheritDoc
     */
    public function getModels(): array
    {
        return $this->innerPlatform->getModels();
    }

    /**
     * @inheritDoc
     */
    public function supportsStreaming(): bool
    {
        return $this->innerPlatform->supportsStreaming();
    }

    /**
     * @inheritDoc
     */
    public function supportsVision(): bool
    {
        return $this->innerPlatform->supportsVision();
    }

    /**
     * @inheritDoc
     */
    public function getResponse(object $response, array $options = []): string
    {
        if (!$this->checkQuota()) {
            throw new \RuntimeException('Token-Quota für diesen Tenant überschritten. Bitte kontaktieren Sie den Administrator.');
        }

        $result = $this->innerPlatform->getResponse($response, $options);
        
        // Record request usage
        $this->recordRequestUsage();

        // Schätze Token-Verbrauch (kann je nach Provider variieren)
        // Für Mistral: ca. 1 Token pro 4 Zeichen
        $estimatedTokens = ceil(strlen($result) / 4);
        $this->recordTokenUsage($estimatedTokens);

        return $result;
    }

    /**
     * @inheritDoc
     */
    public function getStreamingResponse(object $response, array $options = []): \Generator
    {
        if (!$this->checkQuota()) {
            throw new \RuntimeException('Token-Quota für diesen Tenant überschritten. Bitte kontaktieren Sie den Administrator.');
        }

        $this->recordRequestUsage();

        $chunkCount = 0;
        foreach ($this->innerPlatform->getStreamingResponse($response, $options) as $chunk) {
            $chunkCount++;
            
            // Schätze Token-Verbrauch pro Chunk
            // Für Streaming: ca. 1 Token pro 4 Zeichen pro Chunk
            $estimatedTokens = ceil(strlen($chunk) / 4);
            $this->recordTokenUsage($estimatedTokens);

            yield $chunk;
        }

        // Falls keine Chunks, schätze minimalen Verbrauch
        if ($chunkCount === 0) {
            $this->recordTokenUsage(10); // Minimaler Verbrauch
        }
    }

    /**
     * @inheritDoc
     */
    public function getToolResponse(object $response, array $options = []): string
    {
        if (!$this->checkQuota()) {
            throw new \RuntimeException('Token-Quota für diesen Tenant überschritten. Bitte kontaktieren Sie den Administrator.');
        }

        $result = $this->innerPlatform->getToolResponse($response, $options);
        
        $this->recordRequestUsage();

        // Schätze Token-Verbrauch
        $estimatedTokens = ceil(strlen($result) / 4);
        $this->recordTokenUsage($estimatedTokens);

        return $result;
    }

    /**
     * @inheritDoc
     */
    public function getStreamingToolResponse(object $response, array $options = []): \Generator
    {
        if (!$this->checkQuota()) {
            throw new \RuntimeException('Token-Quota für diesen Tenant überschritten. Bitte kontaktieren Sie den Administrator.');
        }

        $this->recordRequestUsage();

        $chunkCount = 0;
        foreach ($this->innerPlatform->getStreamingToolResponse($response, $options) as $chunk) {
            $chunkCount++;
            
            $estimatedTokens = ceil(strlen($chunk) / 4);
            $this->recordTokenUsage($estimatedTokens);

            yield $chunk;
        }

        if ($chunkCount === 0) {
            $this->recordTokenUsage(10);
        }
    }

    /**
     * @inheritDoc
     */
    public function embed(array $options = []): array
    {
        if (!$this->checkQuota()) {
            throw new \RuntimeException('Token-Quota für diesen Tenant überschritten. Bitte kontaktieren Sie den Administrator.');
        }

        $result = $this->innerPlatform->embed($options);
        
        $this->recordRequestUsage();

        // Embedding: ca. Token = Anzahl der Eingabe-Tokens
        $inputLength = strlen($options['input'] ?? '');
        $estimatedTokens = ceil($inputLength / 4);
        $this->recordTokenUsage($estimatedTokens);

        return $result;
    }

    /**
     * @inheritDoc
     */
    public function countTokens(string $text): int
    {
        return $this->innerPlatform->countTokens($text);
    }

    /**
     * @inheritDoc
     */
    public function getMetadata(): array
    {
        return $this->innerPlatform->getMetadata();
    }

    /**
     * @inheritDoc
     */
    public function invoke(Symfony\AI\Platform\Model|string $model, object|array|string $input, array $options = []): Symfony\AI\Platform\Result\DeferredResult
    {
        if (!$this->checkQuota()) {
            throw new \RuntimeException('Token-Quota für diesen Tenant überschritten. Bitte kontaktieren Sie den Administrator.');
        }

        $this->recordRequestUsage();
        return $this->innerPlatform->invoke($model, $input, $options);
    }

    /**
     * @inheritDoc
     */
    public function getModelCatalog(): Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface
    {
        return $this->innerPlatform->getModelCatalog();
    }
}
