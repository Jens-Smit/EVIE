<?php

namespace App\AI\Platform;

use App\AI\Security\AuditLogger;
use App\Repository\TenantQuotaRepository;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Decorator fuer PlatformInterface, der Token-Quotas pro Tenant durchsetzt.
 *
 * Dieser Decorator umschliesst ein PlatformInterface und prueft vor jedem
 * Aufruf, ob der Tenant sein Quota ueberschritten hat.
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
     * Setze den aktuellen Benutzer fuer Quota-Pruefungen.
     */
    public function setUser(UserInterface $user): static
    {
        $this->user = $user;
        return $this;
    }

    /**
     * Get the inner platform.
     */
    public function getInnerPlatform(): PlatformInterface
    {
        return $this->innerPlatform;
    }

    public function invoke(Model|string $model, object|array|string $input, array $options = []): DeferredResult
    {
        if (!$this->checkQuota()) {
            throw new \RuntimeException('Token-Quota fuer diesen Tenant ueberschritten. Bitte kontaktieren Sie den Administrator.');
        }

        $this->recordRequestUsage();

        $inputLength = is_string($input) ? strlen($input) : (is_array($input) ? strlen(json_encode($input)) : 0);
        $this->recordTokenUsage((int) ceil($inputLength / 4));

        return $this->innerPlatform->invoke($model, $input, $options);
    }

    public function getModelCatalog(): ModelCatalogInterface
    {
        return $this->innerPlatform->getModelCatalog();
    }

    /**
     * Pruefe ob das Quota ueberschritten ist.
     */
    private function checkQuota(): bool
    {
        if (null === $this->user) {
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
                'Token-Quota ueberschritten'
            );

            return false;
        }

        return true;
    }

    private function recordTokenUsage(int $tokens): void
    {
        if (null === $this->user) {
            return;
        }

        $this->quotaRepo->recordTokenUsage($this->user->getUserIdentifier(), $tokens);
    }

    private function recordRequestUsage(): void
    {
        if (null === $this->user) {
            return;
        }

        $this->quotaRepo->recordRequestUsage($this->user->getUserIdentifier());
    }
}
