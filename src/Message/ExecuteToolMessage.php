<?php
// src/Message/ExecuteToolMessage.php

namespace App\Message;

use Symfony\Component\Uid\Uuid;

/**
 * Message für die asynchrone Ausführung eines Tools.
 * Wird an den async_tools Transport gesendet und von ExecuteToolMessageHandler verarbeitet.
 */
class ExecuteToolMessage
{
    private Uuid $messageId;
    private string $toolName;
    private array $arguments;
    private string $userIdentifier;
    private string $sessionId;
    private string $correlationId;
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $toolName,
        array $arguments,
        string $userIdentifier,
        string $sessionId,
        string $correlationId = null
    ) {
        $this->messageId = Uuid::v4();
        $this->toolName = $toolName;
        $this->arguments = $arguments;
        $this->userIdentifier = $userIdentifier;
        $this->sessionId = $sessionId;
        $this->correlationId = $correlationId ?? Uuid::v4()->toRfc4122();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getMessageId(): Uuid
    {
        return $this->messageId;
    }

    public function getToolName(): string
    {
        return $this->toolName;
    }

    public function getArguments(): array
    {
        return $this->arguments;
    }

    public function getUserIdentifier(): string
    {
        return $this->userIdentifier;
    }

    public function getSessionId(): string
    {
        return $this->sessionId;
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
            'tool_name' => $this->toolName,
            'arguments' => $this->arguments,
            'user_identifier' => $this->userIdentifier,
            'session_id' => $this->sessionId,
            'correlation_id' => $this->correlationId,
            'created_at' => $this->createdAt->format('c'),
        ];
    }

    /**
     * Erstellt eine ExecuteToolMessage aus einem Array.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['tool_name'],
            $data['arguments'],
            $data['user_identifier'],
            $data['session_id'],
            $data['correlation_id'] ?? null
        );
    }
}
