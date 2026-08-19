<?php

namespace App\Message;

/**
 * Message für das Ergebnis einer asynchronen Sub-Agenten-Ausführung
 * 
 * Diese Message wird vom Message Handler gesendet, nachdem ein Sub-Agent
 * seine Aufgabe abgeschlossen hat.
 */
class SubAgentResultMessage
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
     * @var string Das Ergebnis der Ausführung
     */
    private string $result;

    /**
     * @var bool Ob die Ausführung erfolgreich war
     */
    private bool $isSuccess;

    /**
     * @var string|null Die Fehlermeldung (falls nicht erfolgreich)
     */
    private ?string $errorMessage;

    /**
     * @var int|null Die ID der Eltern-Nachricht
     */
    private ?int $parentMessageId;

    /**
     * @var string|null Die Conversation-ID
     */
    private ?string $conversationId;

    /**
     * @var \DateTimeImmutable|null Der Zeitpunkt, zu dem die Message erstellt wurde
     */
    private ?\DateTimeImmutable $createdAt;

    /**
     * @var \DateTimeImmutable|null Der Zeitpunkt, zu dem die Ausführung gestartet wurde
     */
    private ?\DateTimeImmutable $startedAt;

    /**
     * @var \DateTimeImmutable|null Der Zeitpunkt, zu dem die Ausführung abgeschlossen wurde
     */
    private ?\DateTimeImmutable $completedAt;

    public function __construct(
        string $userIdentifier,
        string $subAgentName,
        string $result,
        bool $isSuccess = true,
        ?string $errorMessage = null,
        ?int $parentMessageId = null,
        ?string $conversationId = null,
        ?\DateTimeImmutable $startedAt = null,
        ?\DateTimeImmutable $completedAt = null
    ) {
        $this->userIdentifier = $userIdentifier;
        $this->subAgentName = $subAgentName;
        $this->result = $result;
        $this->isSuccess = $isSuccess;
        $this->errorMessage = $errorMessage;
        $this->parentMessageId = $parentMessageId;
        $this->conversationId = $conversationId;
        $this->startedAt = $startedAt;
        $this->completedAt = $completedAt;
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

    public function getResult(): string
    {
        return $this->result;
    }

    public function setResult(string $result): void
    {
        $this->result = $result;
    }

    public function isSuccess(): bool
    {
        return $this->isSuccess;
    }

    public function setIsSuccess(bool $isSuccess): void
    {
        $this->isSuccess = $isSuccess;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): void
    {
        $this->errorMessage = $errorMessage;
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

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTimeImmutable $startedAt): void
    {
        $this->startedAt = $startedAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTimeImmutable $completedAt): void
    {
        $this->completedAt = $completedAt;
    }

    /**
     * Gibt die Ausführungsdauer in Sekunden zurück
     * 
     * @return float|null Die Dauer in Sekunden oder null
     */
    public function getExecutionDuration(): ?float
    {
        if (!$this->startedAt || !$this->completedAt) {
            return null;
        }

        return $this->completedAt->getTimestamp() - $this->startedAt->getTimestamp();
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
            'success' => $this->isSuccess,
            'result_length' => strlen($this->result),
            'has_error' => $this->errorMessage !== null,
            'has_parent' => $this->parentMessageId !== null,
            'has_conversation' => $this->conversationId !== null,
            'duration' => $this->getExecutionDuration(),
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
        if ($this->isSuccess) {
            return sprintf(
                "Sub-Agent %s completed successfully for %s",
                $this->subAgentName,
                $this->userIdentifier
            );
        }

        return sprintf(
            "Sub-Agent %s failed for %s: %s",
            $this->subAgentName,
            $this->userIdentifier,
            $this->errorMessage ?? 'Unknown error'
        );
    }

    /**
     * Gibt eine User-freundliche Nachricht zurück
     * 
     * @return string Die Nachricht für den User
     */
    public function getUserMessage(): string
    {
        if ($this->isSuccess) {
            return sprintf(
                "Der Sub-Agent **%s** hat seine Aufgabe erfolgreich abgeschlossen.\n\nErgebnis: %s",
                $this->subAgentName,
                substr($this->result, 0, 200) . (strlen($this->result) > 200 ? '...' : '')
            );
        }

        return sprintf(
            "Der Sub-Agent **%s** ist fehlgeschlagen: %s",
            $this->subAgentName,
            $this->errorMessage ?? 'Unbekannter Fehler'
        );
    }
}
