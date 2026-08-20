<?php

namespace App\Message;

use Symfony\Component\Uid\Ulid;

/**
 * DeadLetterMessage represents a message that has permanently failed
 * and cannot be retried.
 * 
 * This message is sent to the dead letter queue for manual inspection
 * and potential recovery.
 */
class DeadLetterMessage
{
    public const string QUEUE_NAME = 'dead_letter';

    public function __construct(
        public string $originalMessageClass,
        public string $originalMessageId,
        public string $tenantId,
        public string $userId,
        public string $error,
        public int $retryCount = 0,
        public ?string $executionId = null,
        public ?string $correlationId = null,
        public array $metadata = []
    ) {
        if (empty($this->originalMessageId)) {
            $this->originalMessageId = Ulid::generate();
        }
    }

    /**
     * Create a dead letter message from a failed execution.
     */
    public static function createFromExecution(
        string $executionId,
        string $tenantId,
        string $userId,
        string $originalMessageClass,
        string $error,
        int $retryCount = 0,
        ?string $correlationId = null,
        array $metadata = []
    ): self {
        return new self(
            originalMessageClass: $originalMessageClass,
            originalMessageId: $executionId,
            tenantId: $tenantId,
            userId: $userId,
            error: $error,
            retryCount: $retryCount,
            executionId: $executionId,
            correlationId: $correlationId,
            metadata: $metadata
        );
    }

    /**
     * Get the original message class.
     */
    public function getOriginalMessageClass(): string
    {
        return $this->originalMessageClass;
    }

    /**
     * Get the original message ID.
     */
    public function getOriginalMessageId(): string
    {
        return $this->originalMessageId;
    }

    /**
     * Get the tenant ID.
     */
    public function getTenantId(): string
    {
        return $this->tenantId;
    }

    /**
     * Get the user ID.
     */
    public function getUserId(): string
    {
        return $this->userId;
    }

    /**
     * Get the error message.
     */
    public function getError(): string
    {
        return $this->error;
    }

    /**
     * Get the retry count.
     */
    public function getRetryCount(): int
    {
        return $this->retryCount;
    }

    /**
     * Get the execution ID.
     */
    public function getExecutionId(): ?string
    {
        return $this->executionId;
    }

    /**
     * Get the correlation ID.
     */
    public function getCorrelationId(): ?string
    {
        return $this->correlationId;
    }

    /**
     * Get the metadata.
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Check if this message has an associated execution.
     */
    public function hasExecution(): bool
    {
        return $this->executionId !== null;
    }

    /**
     * Convert to array for logging/debugging.
     */
    public function toArray(): array
    {
        return [
            'originalMessageClass' => $this->originalMessageClass,
            'originalMessageId' => $this->originalMessageId,
            'tenantId' => $this->tenantId,
            'userId' => $this->userId,
            'error' => $this->error,
            'retryCount' => $this->retryCount,
            'executionId' => $this->executionId,
            'correlationId' => $this->correlationId,
            'metadata' => $this->metadata,
            'timestamp' => (new \DateTimeImmutable())->format('c'),
        ];
    }

    /**
     * Get a human-readable description of this dead letter message.
     */
    public function getDescription(): string
    {
        return sprintf(
            'Dead Letter: %s (ID: %s) failed after %d retries: %s',
            $this->originalMessageClass,
            $this->originalMessageId,
            $this->retryCount,
            $this->error
        );
    }
}
