<?php
// src/Entity/StreamingSession.php

namespace App\Entity;

use App\Repository\StreamingSessionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Entity für Streaming-Sessions.
 * Speichert den Status und Metadaten von laufenden oder abgeschlossenen Streaming-Sessions.
 */
#[ORM\Entity(repositoryClass: StreamingSessionRepository::class)]
#[ORM\Table(name: 'ai_streaming_sessions')]
#[ORM\HasLifecycleCallbacks]
class StreamingSession
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\Column(type: 'string', length: 255, unique: true)]
    private string $sessionId;

    #[ORM\Column(type: 'string', length: 255)]
    private string $toolName;

    #[ORM\Column(type: 'json')]
    private array $initialArguments = [];

    #[ORM\Column(type: 'string', length: 255)]
    private string $userIdentifier;

    #[ORM\Column(type: 'string', length: 50)]
    private string $status = 'pending'; // pending, running, completed, failed, cancelled

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $currentProgress = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $progressPercentage = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $partialResults = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $finalResult = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $errorData = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $correlationId = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $user = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->sessionId = Uuid::v4()->toRfc4122();
        $this->createdAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function updateTimestamps(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    // Getter & Setter

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    public function setSessionId(string $sessionId): self
    {
        $this->sessionId = $sessionId;
        return $this;
    }

    public function getToolName(): string
    {
        return $this->toolName;
    }

    public function setToolName(string $toolName): self
    {
        $this->toolName = $toolName;
        return $this;
    }

    public function getInitialArguments(): array
    {
        return $this->initialArguments;
    }

    public function setInitialArguments(array $initialArguments): self
    {
        $this->initialArguments = $initialArguments;
        return $this;
    }

    public function getUserIdentifier(): string
    {
        return $this->userIdentifier;
    }

    public function setUserIdentifier(string $userIdentifier): self
    {
        $this->userIdentifier = $userIdentifier;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getCurrentProgress(): ?string
    {
        return $this->currentProgress;
    }

    public function setCurrentProgress(?string $currentProgress): self
    {
        $this->currentProgress = $currentProgress;
        return $this;
    }

    public function getProgressPercentage(): ?float
    {
        return $this->progressPercentage;
    }

    public function setProgressPercentage(?float $progressPercentage): self
    {
        $this->progressPercentage = $progressPercentage;
        return $this;
    }

    public function getPartialResults(): ?array
    {
        return $this->partialResults;
    }

    public function setPartialResults(?array $partialResults): self
    {
        $this->partialResults = $partialResults;
        return $this;
    }

    public function addPartialResult(mixed $result): self
    {
        if ($this->partialResults === null) {
            $this->partialResults = [];
        }
        $this->partialResults[] = $result;
        return $this;
    }

    public function getFinalResult(): ?array
    {
        return $this->finalResult;
    }

    public function setFinalResult(?array $finalResult): self
    {
        $this->finalResult = $finalResult;
        return $this;
    }

    public function getErrorData(): ?array
    {
        return $this->errorData;
    }

    public function setErrorData(?array $errorData): self
    {
        $this->errorData = $errorData;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTimeImmutable $startedAt): self
    {
        $this->startedAt = $startedAt;
        return $this;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTimeImmutable $completedAt): self
    {
        $this->completedAt = $completedAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function getCorrelationId(): ?string
    {
        return $this->correlationId;
    }

    public function setCorrelationId(?string $correlationId): self
    {
        $this->correlationId = $correlationId;
        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;
        return $this;
    }

    // Status-Konstanten
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Prüft, ob die Session aktiv ist (pending oder running).
     */
    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_RUNNING]);
    }

    /**
     * Prüft, ob die Session abgeschlossen ist.
     */
    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED]);
    }

    /**
     * Prüft, ob die Session erfolgreich abgeschlossen wurde.
     */
    public function isSuccessful(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Gibt die Dauer der Session in Sekunden zurück.
     */
    public function getDuration(): ?int
    {
        if ($this->startedAt === null || $this->completedAt === null) {
            return null;
        }

        return $this->completedAt->getTimestamp() - $this->startedAt->getTimestamp();
    }

    /**
     * Gibt die Session als Array zurück.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id?->toRfc4122(),
            'session_id' => $this->sessionId,
            'tool_name' => $this->toolName,
            'initial_arguments' => $this->initialArguments,
            'user_identifier' => $this->userIdentifier,
            'status' => $this->status,
            'current_progress' => $this->currentProgress,
            'progress_percentage' => $this->progressPercentage,
            'partial_results' => $this->partialResults,
            'final_result' => $this->finalResult,
            'error_data' => $this->errorData,
            'created_at' => $this->createdAt->format('c'),
            'started_at' => $this->startedAt?->format('c'),
            'completed_at' => $this->completedAt?->format('c'),
            'updated_at' => $this->updatedAt?->format('c'),
            'correlation_id' => $this->correlationId,
            'duration' => $this->getDuration(),
        ];
    }
}
