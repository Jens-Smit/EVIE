<?php

namespace App\Repository;

use App\Entity\TenantQuota;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TenantQuota>
 *
 * @method TenantQuota|null find($id, $lockMode = null, $lockVersion = null)
 * @method TenantQuota|null findOneBy(array $criteria, array $orderBy = null)
 * @method TenantQuota[]    findAll()
 * @method TenantQuota[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class TenantQuotaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TenantQuota::class);
    }

    /**
     * Save a TenantQuota
     */
    public function save(TenantQuota $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Remove a TenantQuota
     */
    public function remove(TenantQuota $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Find or create quota for a user
     */
    public function findOrCreate(string $userIdentifier): TenantQuota
    {
        $quota = $this->findOneBy(['userIdentifier' => $userIdentifier]);
        
        if (null === $quota) {
            $quota = new TenantQuota();
            $quota->setUserIdentifier($userIdentifier);
            $this->save($quota, true);
        }
        
        return $quota;
    }

    /**
     * Find quota by user identifier
     */
    public function findByUserIdentifier(string $userIdentifier): ?TenantQuota
    {
        return $this->findOneBy(['userIdentifier' => $userIdentifier]);
    }

    /**
     * Record token usage for a user
     */
    public function recordTokenUsage(string $userIdentifier, int $tokens): void
    {
        $quota = $this->findOrCreate($userIdentifier);
        $quota->addToCurrentDayUsage($tokens);
        $quota->setUpdatedAt(new DateTimeImmutable());
        
        $this->save($quota, true);
    }

    /**
     * Record request usage for a user
     */
    public function recordRequestUsage(string $userIdentifier): void
    {
        $quota = $this->findOrCreate($userIdentifier);
        $quota->addToCurrentHourUsage(1);
        $quota->setUpdatedAt(new DateTimeImmutable());
        
        $this->save($quota, true);
    }

    /**
     * Check if quota is exceeded for a user
     */
    public function isQuotaExceeded(string $userIdentifier): bool
    {
        $quota = $this->findOrCreate($userIdentifier);
        
        // Check daily tokens
        if ($quota->isDailyTokensExceeded()) {
            return true;
        }
        
        // Check hourly requests
        if ($quota->isHourlyRequestsExceeded()) {
            return true;
        }
        
        return false;
    }

    /**
     * Get quota usage for a user
     */
    public function getQuotaUsage(string $userIdentifier): array
    {
        $quota = $this->findOrCreate($userIdentifier);
        
        return [
            'max_tokens_per_day' => $quota->getMaxTokensPerDay(),
            'current_day_usage' => $quota->getCurrentDayUsage(),
            'remaining_daily_tokens' => $quota->getRemainingDailyTokens(),
            'daily_usage_percentage' => $quota->getDailyUsagePercentage(),
            'max_requests_per_hour' => $quota->getMaxRequestsPerHour(),
            'current_hour_usage' => $quota->getCurrentHourUsage(),
            'remaining_hourly_requests' => $quota->getRemainingHourlyRequests(),
            'hourly_usage_percentage' => $quota->getHourlyUsagePercentage(),
            'max_concurrent_requests' => $quota->getMaxConcurrentRequests(),
        ];
    }

    /**
     * Reset daily usage for all tenants
     */
    public function resetAllDailyUsage(): int
    {
        $quotas = $this->findAll();
        $count = 0;
        
        foreach ($quotas as $quota) {
            $quota->resetDailyUsage();
            $this->save($quota);
            $count++;
        }
        
        $this->getEntityManager()->flush();
        
        return $count;
    }

    /**
     * Reset hourly usage for all tenants
     */
    public function resetAllHourlyUsage(): int
    {
        $quotas = $this->findAll();
        $count = 0;
        
        foreach ($quotas as $quota) {
            $quota->resetHourlyUsage();
            $this->save($quota);
            $count++;
        }
        
        $this->getEntityManager()->flush();
        
        return $count;
    }

    /**
     * Get all quotas for admin view
     */
    public function getAllQuotas(): array
    {
        $quotas = $this->findAll();
        $result = [];
        
        foreach ($quotas as $quota) {
            $result[] = [
                'user_identifier' => $quota->getUserIdentifier(),
                'max_tokens_per_day' => $quota->getMaxTokensPerDay(),
                'current_day_usage' => $quota->getCurrentDayUsage(),
                'daily_usage_percentage' => $quota->getDailyUsagePercentage(),
                'max_requests_per_hour' => $quota->getMaxRequestsPerHour(),
                'current_hour_usage' => $quota->getCurrentHourUsage(),
                'hourly_usage_percentage' => $quota->getHourlyUsagePercentage(),
                'is_custom' => $quota->isCustom(),
                'created_at' => $quota->getCreatedAt(),
            ];
        }
        
        return $result;
    }

    /**
     * Update quota settings for a user
     */
    public function updateQuotaSettings(
        string $userIdentifier,
        ?int $maxTokensPerDay = null,
        ?int $maxRequestsPerHour = null,
        ?int $maxConcurrentRequests = null
    ): TenantQuota {
        $quota = $this->findOrCreate($userIdentifier);
        
        if (null !== $maxTokensPerDay) {
            $quota->setMaxTokensPerDay($maxTokensPerDay);
        }
        
        if (null !== $maxRequestsPerHour) {
            $quota->setMaxRequestsPerHour($maxRequestsPerHour);
        }
        
        if (null !== $maxConcurrentRequests) {
            $quota->setMaxConcurrentRequests($maxConcurrentRequests);
        }
        
        $quota->setIsCustom(true);
        $quota->setUpdatedAt(new DateTimeImmutable());
        
        $this->save($quota, true);
        
        return $quota;
    }
}
