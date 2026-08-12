<?php
// src/Message/EndStreamingSessionMessage.php

namespace App\Message;

use Symfony\Component\Uid\Uuid;

/**
 * Message für das Ende einer Streaming-Session.
 * Wird gesendet, wenn ein Tool mit Streaming-Unterstützung abgeschlossen wird.
 */
class EndStreamingSessionMessage
{
    private Uuid $messageId;
    private string $sessionId;
    private string $toolName;
    private bool $success;
    private string $finalStatus;
    private array $metadata = [];
    private string $correlationId;
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $sessionId,
        string $toolName,
        bool $success = true,
        string $finalStatus = 'completed',
        array $metadata = [],
        string $correlationId = null
    ) {
        $this->messageId = Uuid::v4();
        $this->sessionId = $sessionId;
        $this->toolName = $toolName;
        $this->success = $success;
        $this->finalStatus = $finalStatus;
        $this->metadata = $metadata;
        $this->correlationId = $correlationId ?? Uuid::v4()->toRfc4122();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getMessageId(): Uuid
    {
        return $this->messageId;
    }

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    public function getToolName(): string
    {
        return $this->toolName;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getFinalStatus(): string
    {
        return $this->finalStatus;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getCorrelationId(): string
    {
        return $this->correlationId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Erstellt eine erfolgreiche End-Message.
     */
    public static function createSuccess(
        string $sessionId,
        string $toolName,
        array $metadata = [],
        string $correlationId = null
    ): self {
        return new self(
            $sessionId,
            $toolName,
            true,
            'completed',
            $metadata,
            $correlationId
        );
    }

    /**
     * Erstellt eine fehlgeschlagene End-Message.
     */
    public static function createFailure(
        string $sessionId,
        string $toolName,
        string $errorMessage,
        array $metadata = [],
        string $correlationId = null
    ): self {
        return new self(
            $sessionId,
            $toolName,
            false,
            'failed',
            array_merge($metadata, ['error' => $errorMessage]),
            $correlationId
        );
    }

    /**
     * Erstellt eine abgebrochene End-Message.
     */
    public static function createCancelled(
        string $sessionId,
        string $toolName,
        string $reason = 'User cancelled',
        string $correlationId = null
    ): self {
        return new self(
            $sessionId,
            $toolName,
            false,
            'cancelled',
            ['reason' => $reason],
            $correlationId
        );
    }

    /**
     * Gibt die Message als Array für die Serialisierung zurück.
     */
    public function toArray(): array
    {
        return [
            'message_id' => $this->messageId->toRfc4122(),
            'session_id' => $this->sessionId,
            'tool_name' => $this->toolName,
            'success' => $this->success,
            'final_status' => $this->finalStatus,
            'metadata' => $this->metadata,
            'correlation_id' => $this->correlationId,
            'created_at' => $this->createdAt->format('c'),
        ];
    }

    /**
     * Erstellt eine EndStreamingSessionMessage aus einem Array.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['session_id'],
            $data['tool_name'],
            $data['success'] ?? true,
            $data['final_status'] ?? 'completed',
            $data['metadata'] ?? [],
            $data['correlation_id'] ?? null
        );
    }
}
