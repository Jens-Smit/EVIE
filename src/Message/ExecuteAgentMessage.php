<?php

namespace App\Message;

use Symfony\Component\Uid\Ulid;

/**
 * ExecuteAgentMessage represents a request to execute an agent asynchronously.
 * 
 * This message is used with Symfony Messenger to enable async agent execution.
 * It contains all necessary information to start an agent execution.
 * 
 * @see https://symfony.com/doc/current/messenger.html
 */
class ExecuteAgentMessage
{
    public const string QUEUE_NAME = 'agent_execution';
    
    public function __construct(
        public string $executionId,
        public string $userId,
        public string $tenantId,
        public string $agentName,
        public ?string $conversationId = null,
        public ?string $parentExecutionId = null,
        public ?string $idempotencyKey = null,
        public ?string $correlationId = null,
        public array $parameters = [],
        public array $metadata = [],
        public int $retryCount = 0,
        public ?string $scheduledTaskId = null
    ) {
        // Generate IDs if not provided
        if (empty($this->executionId)) {
            $this->executionId = Ulid::generate();
        }
        
        if (empty($this->idempotencyKey)) {
            $this->idempotencyKey = Ulid::generate();
        }
        
        if (empty($this->correlationId)) {
            $this->correlationId = Ulid::generate();
        }
    }

    /**
     * Create a new message for immediate agent execution.
     */
    public static function create(
        string $userId,
        string $tenantId,
        string $agentName,
        array $parameters = [],
        array $metadata = []
    ): self {
        return new self(
            executionId: Ulid::generate(),
            userId: $userId,
            tenantId: $tenantId,
            agentName: $agentName,
            conversationId: $metadata['conversationId'] ?? null,
            parentExecutionId: $metadata['parentExecutionId'] ?? null,
            idempotencyKey: $metadata['idempotencyKey'] ?? Ulid::generate(),
            correlationId: $metadata['correlationId'] ?? Ulid::generate(),
            parameters: $parameters,
            metadata: $metadata
        );
    }

    /**
     * Create a new message from a scheduled task.
     */
    public static function createFromScheduledTask(
        string $scheduledTaskId,
        string $userId,
        string $tenantId,
        string $agentName,
        array $parameters = [],
        array $metadata = []
    ): self {
        return new self(
            executionId: Ulid::generate(),
            userId: $userId,
            tenantId: $tenantId,
            agentName: $agentName,
            scheduledTaskId: $scheduledTaskId,
            idempotencyKey: $metadata['idempotencyKey'] ?? Ulid::generate(),
            correlationId: $metadata['correlationId'] ?? Ulid::generate(),
            parameters: $parameters,
            metadata: $metadata
        );
    }

    /**
     * Get the execution identifier.
     */
    public function getExecutionId(): string
    {
        return $this->executionId;
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
     * Get the agent name.
     */
    public function getAgentName(): string
    {
        return $this->agentName;
    }

    /**
     * Get the conversation ID.
     */
    public function getConversationId(): ?string
    {
        return $this->conversationId;
    }

    /**
     * Get the parent execution ID.
     */
    public function getParentExecutionId(): ?string
    {
        return $this->parentExecutionId;
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
     * Get the parameters.
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    /**
     * Get the metadata.
     */
    public function getMetadata(): array
    {
        return $this->metadata;
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
     * Get the scheduled task ID.
     */
    public function getScheduledTaskId(): ?string
    {
        return $this->scheduledTaskId;
    }

    /**
     * Check if this message is from a scheduled task.
     */
    public function isFromScheduledTask(): bool
    {
        return $this->scheduledTaskId !== null;
    }

    /**
     * Convert to array for logging/debugging.
     */
    public function toArray(): array
    {
        return [
            'executionId' => $this->executionId,
            'userId' => $this->userId,
            'tenantId' => $this->tenantId,
            'agentName' => $this->agentName,
            'conversationId' => $this->conversationId,
            'parentExecutionId' => $this->parentExecutionId,
            'idempotencyKey' => $this->idempotencyKey,
            'correlationId' => $this->correlationId,
            'parameters' => $this->parameters,
            'metadata' => $this->metadata,
            'retryCount' => $this->retryCount,
            'scheduledTaskId' => $this->scheduledTaskId,
        ];
    }

    /**
     * Create a copy of this message with incremented retry count.
     */
    public function createRetryMessage(): self
    {
        return new self(
            executionId: $this->executionId,
            userId: $this->userId,
            tenantId: $this->tenantId,
            agentName: $this->agentName,
            conversationId: $this->conversationId,
            parentExecutionId: $this->parentExecutionId,
            idempotencyKey: $this->idempotencyKey,
            correlationId: $this->correlationId,
            parameters: $this->parameters,
            metadata: $this->metadata,
            retryCount: $this->retryCount + 1,
            scheduledTaskId: $this->scheduledTaskId
        );
    }
}
