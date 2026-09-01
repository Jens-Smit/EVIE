<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\TenantQuotaRepository;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Service für die Verwaltung von Token-Quotas pro Tenant.
 */
class QuotaService
{
    public function __construct(
        private TenantQuotaRepository $quotaRepo,
    ) {
    }

    /**
     * Prüfe ob ein Tenant sein Quota überschritten hat
     */
    public function isQuotaExceeded(string $userIdentifier): bool
    {
        return $this->quotaRepo->isQuotaExceeded($userIdentifier);
    }

    /**
     * Hole die Quota-Nutzung für einen Tenant
     */
    public function getQuotaUsage(string $userIdentifier): array
    {
        return $this->quotaRepo->getQuotaUsage($userIdentifier);
    }

    /**
     * Zeichne Token-Verbrauch auf
     */
    public function recordTokenUsage(string $userIdentifier, int $tokens): void
    {
        $this->quotaRepo->recordTokenUsage($userIdentifier, $tokens);
    }

    /**
     * Zeichne Request-Verbrauch auf
     */
    public function recordRequestUsage(string $userIdentifier): void
    {
        $this->quotaRepo->recordRequestUsage($userIdentifier);
    }

    /**
     * Aktualisiere Quota-Einstellungen für einen Tenant
     */
    public function updateQuotaSettings(
        string $userIdentifier,
        ?int $maxTokensPerDay = null,
        ?int $maxRequestsPerHour = null,
        ?int $maxConcurrentRequests = null
    ): array {
        $quota = $this->quotaRepo->updateQuotaSettings(
            $userIdentifier,
            $maxTokensPerDay,
            $maxRequestsPerHour,
            $maxConcurrentRequests
        );

        return [
            'user_identifier' => $userIdentifier,
            'max_tokens_per_day' => $quota->getMaxTokensPerDay(),
            'max_requests_per_hour' => $quota->getMaxRequestsPerHour(),
            'max_concurrent_requests' => $quota->getMaxConcurrentRequests(),
            'is_custom' => $quota->isCustom(),
        ];
    }

    /**
     * Hole alle Quotas für die Admin-Ansicht
     */
    public function getAllQuotas(): array
    {
        return $this->quotaRepo->getAllQuotas();
    }

    /**
     * Setze den Standard-Quota für einen neuen Tenant
     */
    public function createDefaultQuota(string $userIdentifier): void
    {
        $this->quotaRepo->findOrCreate($userIdentifier);
    }

    /**
     * Reset tägliche Nutzung für alle Tenants
     */
    public function resetAllDailyUsage(): int
    {
        return $this->quotaRepo->resetAllDailyUsage();
    }

    /**
     * Reset stündliche Nutzung für alle Tenants
     */
    public function resetAllHourlyUsage(): int
    {
        return $this->quotaRepo->resetAllHourlyUsage();
    }

    /**
     * Hole die verbleibenden Tokens für einen Tenant
     */
    public function getRemainingTokens(string $userIdentifier): int
    {
        $usage = $this->getQuotaUsage($userIdentifier);
        return $usage['remaining_daily_tokens'];
    }

    /**
     * Hole die verbleibenden Requests für einen Tenant
     */
    public function getRemainingRequests(string $userIdentifier): int
    {
        $usage = $this->getQuotaUsage($userIdentifier);
        return $usage['remaining_hourly_requests'];
    }
}
