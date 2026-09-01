<?php

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Repository\AgentGoalRepository::class)]
#[ORM\Table(name: 'agent_goals')]
#[ORM\Index(name: 'idx_agent_goal_user', columns: ['user_identifier'])]
#[ORM\Index(name: 'idx_agent_goal_status', columns: ['status'])]
#[ORM\Index(name: 'idx_agent_goal_next_run', columns: ['next_run_at'])]
class AgentGoal
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $userIdentifier;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $title;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::STRING, length: 100, nullable: true)]
    private ?string $cronExpression = null;

    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $status = 'paused';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $lastRunAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $nextRunAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $updatedAt = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $capabilityConstraints = [];

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $lastResult = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $executionCount = 0;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $requiresApproval = true;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isApproved = false;

    #[ORM\ManyToOne(targetEntity: UserProfile::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?UserProfile $userProfile = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
        $this->status = 'paused';
        $this->executionCount = 0;
        $this->capabilityConstraints = [];
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

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getCronExpression(): ?string
    {
        return $this->cronExpression;
    }

    public function setCronExpression(?string $cronExpression): static
    {
        $this->cronExpression = $cronExpression;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getLastRunAt(): ?DateTimeImmutable
    {
        return $this->lastRunAt;
    }

    public function setLastRunAt(?DateTimeImmutable $lastRunAt): static
    {
        $this->lastRunAt = $lastRunAt;
        return $this;
    }

    public function getNextRunAt(): ?DateTimeImmutable
    {
        return $this->nextRunAt;
    }

    public function setNextRunAt(?DateTimeImmutable $nextRunAt): static
    {
        $this->nextRunAt = $nextRunAt;
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

    public function getCapabilityConstraints(): ?array
    {
        return $this->capabilityConstraints;
    }

    public function setCapabilityConstraints(?array $capabilityConstraints): static
    {
        $this->capabilityConstraints = $capabilityConstraints;
        return $this;
    }

    public function getLastResult(): ?array
    {
        return $this->lastResult;
    }

    public function setLastResult(?array $lastResult): static
    {
        $this->lastResult = $lastResult;
        return $this;
    }

    public function getExecutionCount(): ?int
    {
        return $this->executionCount;
    }

    public function setExecutionCount(?int $executionCount): static
    {
        $this->executionCount = $executionCount;
        return $this;
    }

    public function incrementExecutionCount(): static
    {
        $this->executionCount = ($this->executionCount ?? 0) + 1;
        return $this;
    }

    public function isRequiresApproval(): bool
    {
        return $this->requiresApproval;
    }

    public function setRequiresApproval(bool $requiresApproval): static
    {
        $this->requiresApproval = $requiresApproval;
        return $this;
    }

    public function isApproved(): bool
    {
        return $this->isApproved;
    }

    public function setIsApproved(bool $isApproved): static
    {
        $this->isApproved = $isApproved;
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
     * Check if the goal is active and ready to run
     */
    public function isActiveAndDue(): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        if (!$this->isApproved) {
            return false;
        }

        if (null === $this->nextRunAt) {
            return false;
        }

        $now = new DateTimeImmutable();
        return $this->nextRunAt <= $now;
    }

    /**
     * Calculate next run time based on cron expression
     */
    public function calculateNextRunAt(): ?DateTimeImmutable
    {
        if (null === $this->cronExpression) {
            return null;
        }

        try {
            $cron = new \Cron\CronExpression($this->cronExpression);
            return new DateTimeImmutable($cron->getNextRunDate('now')->format('Y-m-d H:i:s'));
        } catch (\Exception $e) {
            return null;
        }
    }
}
