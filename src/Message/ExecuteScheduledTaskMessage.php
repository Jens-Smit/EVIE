<?php

namespace App\Message;

use Symfony\Component\Uid\Ulid;

/**
 * ExecuteScheduledTaskMessage represents a request to execute a scheduled task.
 * 
 * This message is dispatched by the scheduler when a task is due for execution.
 * It contains all necessary information to execute the task.
 */
class ExecuteScheduledTaskMessage
{
    public const string QUEUE_NAME = 'scheduled_tasks';

    public function __construct(
        public string $taskId,
        public string $userId,
        public string $tenantId,
        public string $taskName,
        public string $action,
        public array $parameters = [],
        public array $schedule = [],
        public ?string $organizationId = null,
        public ?string $idempotencyKey = null,
        public ?string $correlationId = null,
        public int $retryCount = 0,
        public array $metadata = []
    ) {
        if (empty($this->idempotencyKey)) {
            $this->idempotencyKey = Ulid::generate();
        }

        if (empty($this->correlationId)) {
            $this->correlationId = Ulid::generate();
        }
    }

    /**
     * Create a message from a ScheduledTask entity.
     */
    public static function createFromTask(
        string $taskId,
        string $userId,
        string $tenantId,
        string $taskName,
        string $action,
        array $parameters = [],
        array $schedule = [],
        ?string $organizationId = null,
        array $metadata = []
    ): self {
        return new self(
            taskId: $taskId,
            userId: $userId,
            tenantId: $tenantId,
            taskName: $taskName,
            action: $action,
            parameters: $parameters,
            schedule: $schedule,
            organizationId: $organizationId,
            idempotencyKey: Ulid::generate(),
            correlationId: Ulid::generate(),
            metadata: $metadata
        );
    }

    /**
     * Get the task ID.
     */
    public function getTaskId(): string
    {
        return $this->taskId;
    }

    /**
     * Get the user ID.
     */
    public function getUserId(): string
    {
        return $this->userId;
    }

    /**
     * Get the tenant ID.
     */
    public function getTenantId(): string
    {
        return $this->tenantId;
    }

    /**
     * Get the task name.
     */
    public function getTaskName(): string
    {
        return $this->taskName;
    }

    /**
     * Get the action to execute.
     */
    public function getAction(): string
    {
        return $this->action;
    }

    /**
     * Get the parameters for the action.
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    /**
     * Get the schedule configuration.
     */
    public function getSchedule(): array
    {
        return $this->schedule;
    }

    /**
     * Get the organization ID.
     */
    public function getOrganizationId(): ?string
    {
        return $this->organizationId;
    }

    /**
     * Get the idempotency key.
     */
    public function getIdempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    /**
     * Get the correlation ID.
     */
    public function getCorrelationId(): string
    {
        return $this->correlationId;
    }

    /**
     * Get the retry count.
     */
    public function getRetryCount(): int
    {
        return $this->retryCount;
    }

    /**
     * Increment the retry count.
     */
    public function incrementRetryCount(): void
    {
        $this->retryCount++;
    }

    /**
     * Get the metadata.
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Check if this message has an organization.
     */
    public function hasOrganization(): bool
    {
        return $this->organizationId !== null;
    }

    /**
     * Convert to array for logging/debugging.
     */
    public function toArray(): array
    {
        return [
            'taskId' => $this->taskId,
            'userId' => $this->userId,
            'tenantId' => $this->tenantId,
            'taskName' => $this->taskName,
            'action' => $this->action,
            'parameters' => $this->parameters,
            'schedule' => $this->schedule,
            'organizationId' => $this->organizationId,
            'idempotencyKey' => $this->idempotencyKey,
            'correlationId' => $this->correlationId,
            'retryCount' => $this->retryCount,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Create a copy of this message with incremented retry count.
     */
    public function createRetryMessage(): self
    {
        return new self(
            taskId: $this->taskId,
            userId: $this->userId,
            tenantId: $this->tenantId,
            taskName: $this->taskName,
            action: $this->action,
            parameters: $this->parameters,
            schedule: $this->schedule,
            organizationId: $this->organizationId,
            idempotencyKey: $this->idempotencyKey,
            correlationId: $this->correlationId,
            retryCount: $this->retryCount + 1,
            metadata: $this->metadata
        );
    }

    /**
     * Get a human-readable description of this message.
     */
    public function getDescription(): string
    {
        return sprintf(
            'Execute Task #%s: %s (%s)',
            substr($this->taskId, 0, 8),
            $this->taskName,
            $this->action
        );
    }
}
