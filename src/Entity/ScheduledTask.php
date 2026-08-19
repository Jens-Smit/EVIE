<?php

namespace App\Entity;

use App\Repository\ScheduledTaskRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ScheduledTaskRepository::class)]
#[ORM\Table(name: 'scheduled_tasks')]
class ScheduledTask
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\Column(type: 'text')]
    private string $taskDescription;

    #[ORM\Column(length: 50)]
    private string $taskType;

    #[ORM\Column(type: 'json')]
    private array $parameters = [];

    #[ORM\Column(type: 'datetime_immutable')]
    private ?DateTimeImmutable $scheduledAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $executedAt = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $status = 'pending';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $result = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isRecurring = false;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $recurrencePattern = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $recurrenceInterval = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $nextExecutionAt = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getTaskDescription(): string
    {
        return $this->taskDescription;
    }

    public function setTaskDescription(string $taskDescription): static
    {
        $this->taskDescription = $taskDescription;
        return $this;
    }

    public function getTaskType(): string
    {
        return $this->taskType;
    }

    public function setTaskType(string $taskType): static
    {
        $this->taskType = $taskType;
        return $this;
    }

    public function getParameters(): array
    {
        return $this->parameters;
    }

    public function setParameters(array $parameters): static
    {
        $this->parameters = $parameters;
        return $this;
    }

    public function getScheduledAt(): ?DateTimeImmutable
    {
        return $this->scheduledAt;
    }

    public function setScheduledAt(DateTimeImmutable $scheduledAt): static
    {
        $this->scheduledAt = $scheduledAt;
        return $this;
    }

    public function getExecutedAt(): ?DateTimeImmutable
    {
        return $this->executedAt;
    }

    public function setExecutedAt(?DateTimeImmutable $executedAt): static
    {
        $this->executedAt = $executedAt;
        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getResult(): ?string
    {
        return $this->result;
    }

    public function setResult(?string $result): static
    {
        $this->result = $result;
        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): static
    {
        $this->errorMessage = $errorMessage;
        return $this;
    }

    public function isRecurring(): bool
    {
        return $this->isRecurring;
    }

    public function setIsRecurring(bool $isRecurring): static
    {
        $this->isRecurring = $isRecurring;
        return $this;
    }

    public function getRecurrencePattern(): ?string
    {
        return $this->recurrencePattern;
    }

    public function setRecurrencePattern(?string $recurrencePattern): static
    {
        $this->recurrencePattern = $recurrencePattern;
        return $this;
    }

    public function getRecurrenceInterval(): ?int
    {
        return $this->recurrenceInterval;
    }

    public function setRecurrenceInterval(?int $recurrenceInterval): static
    {
        $this->recurrenceInterval = $recurrenceInterval;
        return $this;
    }

    public function getNextExecutionAt(): ?DateTimeImmutable
    {
        return $this->nextExecutionAt;
    }

    public function setNextExecutionAt(?DateTimeImmutable $nextExecutionAt): static
    {
        $this->nextExecutionAt = $nextExecutionAt;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    /**
     * Wird aufgerufen, bevor die Entity gespeichert wird
     */
    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function updateTimestamps(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }

    /**
     * Gibt an, ob die Aufgabe ausstehend ist
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Gibt an, ob die Aufgabe erfolgreich ausgeführt wurde
     */
    public function isExecuted(): bool
    {
        return $this->status === 'executed';
    }

    /**
     * Gibt an, ob die Aufgabe fehlgeschlagen ist
     */
    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Gibt an, ob die Aufgabe storniert wurde
     */
    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    /**
     * Gibt an, ob die Aufgabe gerade ausgeführt wird
     */
    public function isExecuting(): bool
    {
        return $this->status === 'executing';
    }

    /**
     * Gibt den Task-Typ als lesbaren Text zurück
     */
    public function getTaskTypeLabel(): string
    {
        return match ($this->taskType) {
            'check_email' => 'E-Mails prüfen',
            'create_briefing' => 'Briefing erstellen',
            'custom' => 'Benutzerdefiniert',
            default => $this->taskType
        };
    }

    /**
     * Gibt den Status als lesbaren Text zurück
     */
    public function getStatusLabel(): string
    {
        return match ($this->status) {
            'pending' => 'Ausstehend',
            'executing' => 'Wird ausgeführt',
            'executed' => 'Erfolgreich',
            'failed' => 'Fehlgeschlagen',
            'cancelled' => 'Storniert',
            default => $this->status
        };
    }

    /**
     * Gibt das Wiederholungsmuster als lesbaren Text zurück
     */
    public function getRecurrenceLabel(): string
    {
        if (!$this->isRecurring) {
            return 'Einmalig';
        }

        $pattern = $this->recurrencePattern ?? 'daily';
        $interval = $this->recurrenceInterval ?? 1;

        return match ($pattern) {
            'hourly' => "Alle $interval Stunde(n)",
            'daily' => "Täglich (alle $interval Tag(e))",
            'weekly' => "Wöchentlich (alle $interval Woche(n))",
            'monthly' => "Monatlich (alle $interval Monat(e))",
            'custom' => "Benutzerdefiniert",
            default => $pattern
        };
    }
}
