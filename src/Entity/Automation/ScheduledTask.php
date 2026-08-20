<?php

namespace App\Entity\Automation;

use App\Entity\Tenant\User;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\Organization;
use App\Repository\Automation\ScheduledTaskRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity(repositoryClass: ScheduledTaskRepository::class)]
#[ORM\Table(name: 'scheduled_task')]
#[ORM\Index(name: 'idx_scheduled_task_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_scheduled_task_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_scheduled_task_organization', columns: ['organization_id'])]
#[ORM\Index(name: 'idx_scheduled_task_status', columns: ['status'])]
#[ORM\Index(name: 'idx_scheduled_task_next_run', columns: ['next_run_at'])]
#[ORM\Index(name: 'idx_scheduled_task_created_at', columns: ['created_at'])]
class ScheduledTask
{
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 255, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uid_generator')]
    private ?string $id = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $status = 'pending';

    #[ORM\Column(type: Types::JSON)]
    private array $schedule = [];

    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $action;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $parameters = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $nextRunAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastRunAt = null;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $runCount = 0;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $failureCount = 0;

    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    private ?string $lastStatus = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $lastResult = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $metadata = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    private ?string $timezone = 'UTC';

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'scheduledTasks')]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false)]
    private Tenant $tenant;

    #[ORM\ManyToOne(targetEntity: Organization::class, inversedBy: 'scheduledTasks')]
    #[ORM\JoinColumn(name: 'organization_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Organization $organization = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $lockId = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lockedAt = null;

    public function __construct()
    {
        $this->id = Ulid::generate();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    // --- Getters and Setters ---

    public function getId(): ?string
    {
        return $this->id;
    }

    public function setId(string $id): static
    {
        $this->id = $id;
        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getSchedule(): array
    {
        return $this->schedule;
    }

    public function setSchedule(array $schedule): static
    {
        $this->schedule = $schedule;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function setAction(string $action): static
    {
        $this->action = $action;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getParameters(): ?array
    {
        return $this->parameters;
    }

    public function setParameters(?array $parameters): static
    {
        $this->parameters = $parameters;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function getNextRunAt(): ?\DateTimeImmutable
    {
        return $this->nextRunAt;
    }

    public function setNextRunAt(?\DateTimeImmutable $nextRunAt): static
    {
        $this->nextRunAt = $nextRunAt;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getLastRunAt(): ?\DateTimeImmutable
    {
        return $this->lastRunAt;
    }

    public function setLastRunAt(?\DateTimeImmutable $lastRunAt): static
    {
        $this->lastRunAt = $lastRunAt;
        return $this;
    }

    public function getRunCount(): int
    {
        return $this->runCount;
    }

    public function setRunCount(int $runCount): static
    {
        $this->runCount = $runCount;
        return $this;
    }

    public function incrementRunCount(): static
    {
        $this->runCount++;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getFailureCount(): int
    {
        return $this->failureCount;
    }

    public function setFailureCount(int $failureCount): static
    {
        $this->failureCount = $failureCount;
        return $this;
    }

    public function incrementFailureCount(): static
    {
        $this->failureCount++;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getLastStatus(): ?string
    {
        return $this->lastStatus;
    }

    public function setLastStatus(?string $lastStatus): static
    {
        $this->lastStatus = $lastStatus;
        return $this;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function setLastError(?string $lastError): static
    {
        $this->lastError = $lastError;
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

    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    public function setMetadata(?array $metadata): static
    {
        $this->metadata = $metadata;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getTimezone(): string
    {
        return $this->timezone ?? 'UTC';
    }

    public function setTimezone(string $timezone): static
    {
        $this->timezone = $timezone;
        return $this;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function setTenant(Tenant $tenant): static
    {
        $this->tenant = $tenant;
        return $this;
    }

    public function getOrganization(): ?Organization
    {
        return $this->organization;
    }

    public function setOrganization(?Organization $organization): static
    {
        $this->organization = $organization;
        return $this;
    }

    public function getLockId(): ?string
    {
        return $this->lockId;
    }

    public function setLockId(?string $lockId): static
    {
        $this->lockId = $lockId;
        return $this;
    }

    public function getLockedAt(): ?\DateTimeImmutable
    {
        return $this->lockedAt;
    }

    public function setLockedAt(?\DateTimeImmutable $lockedAt): static
    {
        $this->lockedAt = $lockedAt;
        return $this;
    }

    // --- Custom Methods ---

    /**
     * Get the tenant ID for this task.
     */
    public function getTenantId(): string
    {
        return $this->tenant->getId();
    }

    /**
     * Get the user ID for this task.
     */
    public function getUserId(): string
    {
        return $this->user->getId();
    }

    /**
     * Check if this task belongs to the given user.
     */
    public function belongsToUser(string $userId): bool
    {
        return $this->user->getId() === $userId;
    }

    /**
     * Check if this task belongs to the given tenant.
     */
    public function belongsToTenant(string $tenantId): bool
    {
        return $this->tenant->getId() === $tenantId;
    }

    /**
     * Check if this task belongs to the given organization.
     */
    public function belongsToOrganization(string $organizationId): bool
    {
        if ($this->organization === null) {
            return false;
        }
        return $this->organization->getId() === $organizationId;
    }

    /**
     * Check if this task is currently locked.
     */
    public function isLocked(): bool
    {
        if ($this->lockId === null || $this->lockedAt === null) {
            return false;
        }

        // Lock expires after 1 hour
        $lockExpiry = $this->lockedAt->modify('+1 hour');
        return new \DateTimeImmutable() < $lockExpiry;
    }

    /**
     * Lock this task for execution.
     */
    public function lock(string $lockId): static
    {
        $this->lockId = $lockId;
        $this->lockedAt = new \DateTimeImmutable();
        $this->status = 'running';
        return $this;
    }

    /**
     * Unlock this task.
     */
    public function unlock(): static
    {
        $this->lockId = null;
        $this->lockedAt = null;
        return $this;
    }

    /**
     * Check if this task is due for execution.
     */
    public function isDue(): bool
    {
        if (!$this->isActive) {
            return false;
        }

        if ($this->nextRunAt === null) {
            return false;
        }

        return new \DateTimeImmutable() >= $this->nextRunAt;
    }

    /**
     * Calculate the next run time based on the schedule.
     */
    public function calculateNextRunAt(): \DateTimeImmutable
    {
        $schedule = $this->getSchedule();
        $now = new \DateTimeImmutable();
        $timezone = new \DateTimeZone($this->getTimezone());
        
        // If there's a last run time, start from there, otherwise start from now
        $startFrom = $this->lastRunAt ?? $now;
        
        // For now, implement simple recurrence patterns
        // This will be enhanced in Phase 5
        if (isset($schedule['frequency'])) {
            $frequency = $schedule['frequency'];
            
            switch ($frequency) {
                case 'once':
                    // One-time task, use the scheduled time
                    if (isset($schedule['time'])) {
                        $time = new \DateTimeImmutable($schedule['time'], $timezone);
                        return $time > $now ? $time : $now;
                    }
                    break;
                    
                case 'hourly':
                    // Run every hour
                    return $startFrom->modify('+1 hour');
                    
                case 'daily':
                    // Run every day at the specified time
                    if (isset($schedule['time'])) {
                        $timeParts = explode(':', $schedule['time']);
                        $hour = (int)($timeParts[0] ?? 0);
                        $minute = (int)($timeParts[1] ?? 0);
                        
                        $nextRun = $startFrom->setTime($hour, $minute, 0);
                        if ($nextRun < $now) {
                            $nextRun = $nextRun->modify('+1 day');
                        }
                        return $nextRun;
                    }
                    return $startFrom->modify('+1 day');
                    
                case 'weekly':
                    // Run every week on the specified day at the specified time
                    if (isset($schedule['day']) && isset($schedule['time'])) {
                        $day = $schedule['day'];
                        $timeParts = explode(':', $schedule['time']);
                        $hour = (int)($timeParts[0] ?? 0);
                        $minute = (int)($timeParts[1] ?? 0);
                        
                        $nextRun = $startFrom;
                        $currentDayOfWeek = (int)$nextRun->format('N'); // 1-7 (Monday-Sunday)
                        $targetDayOfWeek = $this->getDayNumber($day);
                        
                        if ($currentDayOfWeek < $targetDayOfWeek) {
                            $daysToAdd = $targetDayOfWeek - $currentDayOfWeek;
                        } elseif ($currentDayOfWeek > $targetDayOfWeek) {
                            $daysToAdd = 7 - ($currentDayOfWeek - $targetDayOfWeek);
                        } else {
                            // Same day, check if we've already passed the time
                            $nextRun = $nextRun->setTime($hour, $minute, 0);
                            if ($nextRun < $now) {
                                $daysToAdd = 7;
                            } else {
                                return $nextRun;
                            }
                        }
                        
                        return $startFrom->modify("+{$daysToAdd} days")->setTime($hour, $minute, 0);
                    }
                    return $startFrom->modify('+7 days');
                    
                case 'monthly':
                    // Run every month on the specified day at the specified time
                    if (isset($schedule['day']) && isset($schedule['time'])) {
                        $dayOfMonth = (int)$schedule['day'];
                        $timeParts = explode(':', $schedule['time']);
                        $hour = (int)($timeParts[0] ?? 0);
                        $minute = (int)($timeParts[1] ?? 0);
                        
                        $nextRun = $startFrom->setDate(
                            (int)$startFrom->format('Y'),
                            (int)$startFrom->format('n'),
                            $dayOfMonth
                        )->setTime($hour, $minute, 0);
                        
                        if ($nextRun < $now) {
                            // Move to next month
                            $nextRun = $nextRun->modify('+1 month');
                        }
                        return $nextRun;
                    }
                    return $startFrom->modify('+30 days');
                    
                case 'cron':
                    // Cron expression parsing will be implemented later
                    return $startFrom->modify('+1 day');
            }
        }

        // Default: run once immediately
        return $now;
    }

    /**
     * Convert day name to day number (1-7, Monday-Sunday).
     */
    private function getDayNumber(string $day): int
    {
        $days = [
            'monday' => 1,
            'tuesday' => 2,
            'wednesday' => 3,
            'thursday' => 4,
            'friday' => 5,
            'saturday' => 6,
            'sunday' => 7,
        ];
        return $days[strtolower($day)] ?? 1;
    }

    /**
     * Convert to array for API responses.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status,
            'schedule' => $this->schedule,
            'action' => $this->action,
            'parameters' => $this->parameters,
            'createdAt' => $this->createdAt->format('c'),
            'updatedAt' => $this->updatedAt->format('c'),
            'nextRunAt' => $this->nextRunAt?->format('c'),
            'lastRunAt' => $this->lastRunAt?->format('c'),
            'runCount' => $this->runCount,
            'failureCount' => $this->failureCount,
            'lastStatus' => $this->lastStatus,
            'lastError' => $this->lastError,
            'lastResult' => $this->lastResult,
            'isActive' => $this->isActive,
            'timezone' => $this->timezone,
            'userId' => $this->user->getId(),
            'tenantId' => $this->tenant->getId(),
            'organizationId' => $this->organization?->getId(),
            'metadata' => $this->metadata,
        ];
    }

    public function __toString(): string
    {
        return sprintf(
            'Task #%s: %s (%s)',
            substr($this->id, 0, 8),
            $this->name,
            $this->status
        );
    }
}
