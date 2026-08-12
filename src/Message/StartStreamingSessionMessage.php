<?php
// src/Message/StartStreamingSessionMessage.php

namespace App\Message;

use Symfony\Component\Uid\Uuid;

/**
 * Message für den Start einer Streaming-Session.
 * Wird gesendet, wenn ein Tool mit Streaming-Unterstützung gestartet wird.
 */
class StartStreamingSessionMessage
{
    private Uuid $messageId;
    private string $sessionId;
    private string $toolName;
    private array $initialArguments;
    private string $userIdentifier;
    private string $correlationId;
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $sessionId,
        string $toolName,
        array $initialArguments,
        string $userIdentifier,
        string $correlationId = null
    ) {
        $this->messageId = Uuid::v4();
        $this->sessionId = $sessionId;
        $this->toolName = $toolName;
        $this->initialArguments = $initialArguments;
        $this->userIdentifier = $userIdentifier;
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

    public function getInitialArguments(): array
    {
        return $this->initialArguments;
    }

    public function getUserIdentifier(): string
    {
        return $this->userIdentifier;
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
     * Gibt die Message als Array für die Serialisierung zurück.
     */
    public function toArray(): array
    {
        return [
            'message_id' => $this->messageId->toRfc4122(),
            'session_id' => $this->sessionId,
            'tool_name' => $this->toolName,
            'initial_arguments' => $this->initialArguments,
            'user_identifier' => $this->userIdentifier,
            'correlation_id' => $this->correlationId,
            'created_at' => $this->createdAt->format('c'),
        ];
    }

    /**
     * Erstellt eine StartStreamingSessionMessage aus einem Array.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['session_id'],
            $data['tool_name'],
            $data['initial_arguments'],
            $data['user_identifier'],
            $data['correlation_id'] ?? null
        );
    }
}
