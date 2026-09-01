<?php

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Repository\TenantQuotaRepository::class)]
#[ORM\Table(name: 'tenant_quotas')]
#[ORM\Index(name: 'idx_tenant_quota_user', columns: ['user_identifier'])]
#[ORM\UniqueConstraint(name: 'uniq_tenant_quota_user', columns: ['user_identifier'])]
class TenantQuota
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $userIdentifier;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 100000])]
    private int $maxTokensPerDay = 100000;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 1000])]
    private int $maxRequestsPerHour = 1000;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 100])]
    private int $maxConcurrentRequests = 100;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isCustom = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $updatedAt = null;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $currentDayUsage = 0;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $currentHourUsage = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $lastResetAt = null;

    #[ORM\OneToOne(targetEntity: UserProfile::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?UserProfile $userProfile = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUserIdentifier(): string
    {
        return $this->userIdentifier;
    }

    public function setUserIdentifier(string $userIdentifier): static
    {
        $this->userIdentifier = $userIdentifier;
        return $this;
    }

    public function getMaxTokensPerDay(): int
    {
        return $this->maxTokensPerDay;
    }

    public function setMaxTokensPerDay(int $maxTokensPerDay): static
    {
        $this->maxTokensPerDay = $maxTokensPerDay;
        return $this;
    }

    public function getMaxRequestsPerHour(): int
    {
        return $this->maxRequestsPerHour;
    }

    public function setMaxRequestsPerHour(int $maxRequestsPerHour): static
    {
        $this->maxRequestsPerHour = $maxRequestsPerHour;
        return $this;
    }

    public function getMaxConcurrentRequests(): int
    {
        return $this->maxConcurrentRequests;
    }

    public function setMaxConcurrentRequests(int $maxConcurrentRequests): static
    {
        $this->maxConcurrentRequests = $maxConcurrentRequests;
        return $this;
    }

    public function isCustom(): bool
    {
        return $this->isCustom;
    }

    public function setIsCustom(bool $isCustom): static
    {
        $this->isCustom = $isCustom;
        return $this;
    }

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function getCurrentDayUsage(): int
    {
        return $this->currentDayUsage;
    }

    public function setCurrentDayUsage(int $currentDayUsage): static
    {
        $this->currentDayUsage = $currentDayUsage;
        return $this;
    }

    public function addToCurrentDayUsage(int $tokens): static
    {
        $this->currentDayUsage += $tokens;
        return $this;
    }

    public function getCurrentHourUsage(): int
    {
        return $this->currentHourUsage;
    }

    public function setCurrentHourUsage(int $currentHourUsage): static
    {
        $this->currentHourUsage = $currentHourUsage;
        return $this;
    }

    public function addToCurrentHourUsage(int $requests): static
    {
        $this->currentHourUsage += $requests;
        return $this;
    }

    public function getLastResetAt(): ?DateTimeImmutable
    {
        return $this->lastResetAt;
    }

    public function setLastResetAt(?DateTimeImmutable $lastResetAt): static
    {
        $this->lastResetAt = $lastResetAt;
        return $this;
    }

    public function getUserProfile(): ?UserProfile
    {
        return $this->userProfile;
    }

    public function setUserProfile(?UserProfile $userProfile): static
    {
        $this->userProfile = $userProfile;
        return $this;
    }

    /**
     * Check if quota is exceeded for daily tokens
     */
    public function isDailyTokensExceeded(): bool
    {
        return $this->currentDayUsage >= $this->maxTokensPerDay;
    }

    /**
     * Check if quota is exceeded for hourly requests
     */
    public function isHourlyRequestsExceeded(): bool
    {
        return $this->currentHourUsage >= $this->maxRequestsPerHour;
    }

    /**
     * Get remaining daily tokens
     */
    public function getRemainingDailyTokens(): int
    {
        return max(0, $this->maxTokensPerDay - $this->currentDayUsage);
    }

    /**
     * Get remaining hourly requests
     */
    public function getRemainingHourlyRequests(): int
    {
        return max(0, $this->maxRequestsPerHour - $this->currentHourUsage);
    }

    /**
     * Reset daily usage
     */
    public function resetDailyUsage(): static
    {
        $this->currentDayUsage = 0;
        $this->setLastResetAt(new DateTimeImmutable());
        return $this;
    }

    /**
     * Reset hourly usage
     */
    public function resetHourlyUsage(): static
    {
        $this->currentHourUsage = 0;
        return $this;
    }

    /**
     * Get usage percentage for daily tokens
     */
    public function getDailyUsagePercentage(): float
    {
        if ($this->maxTokensPerDay <= 0) {
            return 0.0;
        }
        return min(100.0, ($this->currentDayUsage / $this->maxTokensPerDay) * 100);
    }

    /**
     * Get usage percentage for hourly requests
     */
    public function getHourlyUsagePercentage(): float
    {
        if ($this->maxRequestsPerHour <= 0) {
            return 0.0;
        }
        return min(100.0, ($this->currentHourUsage / $this->maxRequestsPerHour) * 100);
    }
}
