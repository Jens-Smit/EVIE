<?php

namespace App\Message;

/**
 * Message für die asynchrone Ausführung eines Sub-Agenten
 * 
 * Diese Message wird an den Symfony Messenger gesendet, um einen Sub-Agenten
 * im Hintergrund auszuführen, während der Main-Agent synchron weiterläuft.
 */
class ExecuteSubAgentMessage
{
    /**
     * @var string Der User-Identifier
     */
    private string $userIdentifier;

    /**
     * @var string Der Name des Sub-Agenten
     */
    private string $subAgentName;

    /**
     * @var string Die Aufgabe, die der Sub-Agent ausführen soll
     */
    private string $task;

    /**
     * @var array Zusätzlicher Kontext für die Aufgabe
     */
    private array $context;

    /**
     * @var int|null Die ID der Eltern-Nachricht (für Zuordnung)
     */
    private ?int $parentMessageId;

    /**
     * @var string|null Die Conversation-ID (für Zuordnung zur Unterhaltung)
     */
    private ?string $conversationId;

    /**
     * @var \DateTimeImmutable|null Der Zeitpunkt, zu dem die Message erstellt wurde
     */
    private ?\DateTimeImmutable $createdAt;

    public function __construct(
        string $userIdentifier,
        string $subAgentName,
        string $task,
        array $context = [],
        ?int $parentMessageId = null,
        ?string $conversationId = null
    ) {
        $this->userIdentifier = $userIdentifier;
        $this->subAgentName = $subAgentName;
        $this->task = $task;
        $this->context = $context;
        $this->parentMessageId = $parentMessageId;
        $this->conversationId = $conversationId;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getUserIdentifier(): string
    {
        return $this->userIdentifier;
    }

    public function setUserIdentifier(string $userIdentifier): void
    {
        $this->userIdentifier = $userIdentifier;
    }

    public function getSubAgentName(): string
    {
        return $this->subAgentName;
    }

    public function setSubAgentName(string $subAgentName): void
    {
        $this->subAgentName = $subAgentName;
    }

    public function getTask(): string
    {
        return $this->task;
    }

    public function setTask(string $task): void
    {
        $this->task = $task;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function setContext(array $context): void
    {
        $this->context = $context;
    }

    public function getParentMessageId(): ?int
    {
        return $this->parentMessageId;
    }

    public function setParentMessageId(?int $parentMessageId): void
    {
        $this->parentMessageId = $parentMessageId;
    }

    public function getConversationId(): ?string
    {
        return $this->conversationId;
    }

    public function setConversationId(?string $conversationId): void
    {
        $this->conversationId = $conversationId;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(?\DateTimeImmutable $createdAt): void
    {
        $this->createdAt = $createdAt;
    }

    /**
     * Gibt eine Zusammenfassung der Message für Logging-Zwecke zurück
     * 
     * @return array Array mit den wichtigsten Informationen
     */
    public function getSummary(): array
    {
        return [
            'user_identifier' => $this->userIdentifier,
            'sub_agent' => $this->subAgentName,
            'task_length' => strlen($this->task),
            'has_context' => !empty($this->context),
            'has_parent' => $this->parentMessageId !== null,
            'has_conversation' => $this->conversationId !== null,
            'created_at' => $this->createdAt?->format('c')
        ];
    }

    /**
     * Gibt eine kurze Beschreibung der Message zurück
     * 
     * @return string Die Beschreibung
     */
    public function getDescription(): string
    {
        return sprintf(
            "Execute %s for %s: %s",
            $this->subAgentName,
            $this->userIdentifier,
            substr($this->task, 0, 50) . (strlen($this->task) > 50 ? '...' : '')
        );
    }
}
