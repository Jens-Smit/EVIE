<?php
// src/Message/StreamChunkMessage.php

namespace App\Message;

use Symfony\Component\Uid\Uuid;

/**
 * Message für einzelne Streaming-Chunks.
 * Wird für die Übertragung von Teilergebnissen oder Fortschrittsupdates verwendet.
 * Dies ist eine vereinfachte Version von StreamToolResponseMessage für
 * interne Verarbeitung.
 */
class StreamChunkMessage
{
    private Uuid $messageId;
    private string $sessionId;
    private string $toolName;
    private mixed $data;
    private string $type; // 'progress', 'data', 'log', 'status'
    private int $sequenceNumber;
    private string $correlationId;
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $sessionId,
        string $toolName,
        mixed $data,
        string $type = 'data',
        int $sequenceNumber = 1,
        string $correlationId = null
    ) {
        $this->messageId = Uuid::v4();
        $this->sessionId = $sessionId;
        $this->toolName = $toolName;
        $this->data = $data;
        $this->type = $type;
        $this->sequenceNumber = $sequenceNumber;
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

    public function getData(): mixed
    {
        return $this->data;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getSequenceNumber(): int
    {
        return $this->sequenceNumber;
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
     * Erstellt eine Progress-Chunk-Message.
     */
    public static function createProgress(
        string $sessionId,
        string $toolName,
        float $percentage,
        string $message,
        int $sequenceNumber,
        string $correlationId = null
    ): self {
        return new self(
            $sessionId,
            $toolName,
            [
                'percentage' => $percentage,
                'message' => $message,
            ],
            'progress',
            $sequenceNumber,
            $correlationId
        );
    }

    /**
     * Erstellt eine Data-Chunk-Message.
     */
    public static function createData(
        string $sessionId,
        string $toolName,
        mixed $data,
        int $sequenceNumber,
        string $correlationId = null
    ): self {
        return new self(
            $sessionId,
            $toolName,
            $data,
            'data',
            $sequenceNumber,
            $correlationId
        );
    }

    /**
     * Erstellt eine Log-Chunk-Message.
     */
    public static function createLog(
        string $sessionId,
        string $toolName,
        string $logMessage,
        string $level = 'info',
        int $sequenceNumber,
        string $correlationId = null
    ): self {
        return new self(
            $sessionId,
            $toolName,
            [
                'level' => $level,
                'message' => $logMessage,
                'timestamp' => (new \DateTimeImmutable())->format('c'),
            ],
            'log',
            $sequenceNumber,
            $correlationId
        );
    }

    /**
     * Erstellt eine Status-Chunk-Message.
     */
    public static function createStatus(
        string $sessionId,
        string $toolName,
        string $status,
        array $details = [],
        int $sequenceNumber,
        string $correlationId = null
    ): self {
        return new self(
            $sessionId,
            $toolName,
            array_merge(['status' => $status], $details),
            'status',
            $sequenceNumber,
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
            'data' => $this->data,
            'type' => $this->type,
            'sequence_number' => $this->sequenceNumber,
            'correlation_id' => $this->correlationId,
            'created_at' => $this->createdAt->format('c'),
        ];
    }

    /**
     * Erstellt eine StreamChunkMessage aus einem Array.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['session_id'],
            $data['tool_name'],
            $data['data'],
            $data['type'] ?? 'data',
            $data['sequence_number'] ?? 1,
            $data['correlation_id'] ?? null
        );
    }
}
