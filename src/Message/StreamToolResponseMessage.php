<?php
// src/Message/StreamToolResponseMessage.php

namespace App\Message;

use Symfony\Component\Uid\Uuid;

/**
 * Message für Streaming-Antworten (Chunks).
 * Wird an den streaming Transport gesendet und enthält Teilergebnisse
 * oder Fortschrittsinformationen von laufenden Tool-Executions.
 */
class StreamToolResponseMessage
{
    private Uuid $messageId;
    private string $sessionId;
    private string $toolName;
    private mixed $chunk;
    private string $chunkType; // 'progress', 'partial_result', 'final_result', 'error'
    private bool $isFinal;
    private int $chunkNumber;
    private int $totalChunks;
    private string $correlationId;
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $sessionId,
        string $toolName,
        mixed $chunk,
        string $chunkType = 'partial_result',
        bool $isFinal = false,
        int $chunkNumber = 1,
        int $totalChunks = 1,
        string $correlationId = null
    ) {
        $this->messageId = Uuid::v4();
        $this->sessionId = $sessionId;
        $this->toolName = $toolName;
        $this->chunk = $chunk;
        $this->chunkType = $chunkType;
        $this->isFinal = $isFinal;
        $this->chunkNumber = $chunkNumber;
        $this->totalChunks = $totalChunks;
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

    public function getChunk(): mixed
    {
        return $this->chunk;
    }

    public function getChunkType(): string
    {
        return $this->chunkType;
    }

    public function isFinal(): bool
    {
        return $this->isFinal;
    }

    public function getChunkNumber(): int
    {
        return $this->chunkNumber;
    }

    public function getTotalChunks(): int
    {
        return $this->totalChunks;
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
     * Gibt den Fortschritt in Prozent zurück (falls totalChunks > 0).
     */
    public function getProgress(): float
    {
        if ($this->totalChunks <= 0) {
            return 0.0;
        }

        return min(100.0, ($this->chunkNumber / $this->totalChunks) * 100);
    }

    /**
     * Erstellt eine Progress-Message.
     */
    public static function createProgress(
        string $sessionId,
        string $toolName,
        float $progress,
        string $message,
        string $correlationId = null
    ): self {
        return new self(
            $sessionId,
            $toolName,
            [
                'type' => 'progress',
                'progress' => $progress,
                'message' => $message,
                'timestamp' => (new \DateTimeImmutable())->format('c'),
            ],
            'progress',
            false,
            0,
            0,
            $correlationId
        );
    }

    /**
     * Erstellt eine Partial Result Message.
     */
    public static function createPartialResult(
        string $sessionId,
        string $toolName,
        mixed $partialResult,
        int $chunkNumber,
        int $totalChunks,
        string $correlationId = null
    ): self {
        return new self(
            $sessionId,
            $toolName,
            $partialResult,
            'partial_result',
            false,
            $chunkNumber,
            $totalChunks,
            $correlationId
        );
    }

    /**
     * Erstellt eine Final Result Message.
     */
    public static function createFinalResult(
        string $sessionId,
        string $toolName,
        mixed $finalResult,
        string $correlationId = null
    ): self {
        return new self(
            $sessionId,
            $toolName,
            $finalResult,
            'final_result',
            true,
            1,
            1,
            $correlationId
        );
    }

    /**
     * Erstellt eine Error Message.
     */
    public static function createError(
        string $sessionId,
        string $toolName,
        string $errorMessage,
        array $errorDetails = [],
        string $correlationId = null
    ): self {
        return new self(
            $sessionId,
            $toolName,
            [
                'type' => 'error',
                'error' => $errorMessage,
                'details' => $errorDetails,
                'timestamp' => (new \DateTimeImmutable())->format('c'),
            ],
            'error',
            true,
            1,
            1,
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
            'chunk' => $this->chunk,
            'chunk_type' => $this->chunkType,
            'is_final' => $this->isFinal,
            'chunk_number' => $this->chunkNumber,
            'total_chunks' => $this->totalChunks,
            'correlation_id' => $this->correlationId,
            'created_at' => $this->createdAt->format('c'),
            'progress' => $this->getProgress(),
        ];
    }

    /**
     * Erstellt eine StreamToolResponseMessage aus einem Array.
     */
    public static function fromArray(array $data): self
    {
        $message = new self(
            $data['session_id'],
            $data['tool_name'],
            $data['chunk'],
            $data['chunk_type'] ?? 'partial_result',
            $data['is_final'] ?? false,
            $data['chunk_number'] ?? 1,
            $data['total_chunks'] ?? 1,
            $data['correlation_id'] ?? null
        );

        // Überschreibe createdAt, falls vorhanden
        if (isset($data['created_at'])) {
            $message = new \ReflectionClass(self);
            $property = $message->getProperty('createdAt');
            $property->setAccessible(true);
            $property->setValue($message, new \DateTimeImmutable($data['created_at']));
        }

        return $message;
    }
}
