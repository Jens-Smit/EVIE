<?php

namespace App\Entity\AI;

use App\Entity\Tenant\User;
use App\Entity\Tenant\Tenant;
use App\Repository\AI\AgentExecutionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity(repositoryClass: AgentExecutionRepository::class)]
#[ORM\Table(name: 'agent_execution')]
#[ORM\Index(name: 'idx_agent_execution_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_agent_execution_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_agent_execution_conversation', columns: ['conversation_id'])]
#[ORM\Index(name: 'idx_agent_execution_status', columns: ['status'])]
#[ORM\Index(name: 'idx_agent_execution_created_at', columns: ['created_at'])]
#[ORM\Index(name: 'idx_agent_execution_parent', columns: ['parent_execution_id'])]
class AgentExecution
{
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 255, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uid_generator')]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false)]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false)]
    private Tenant $tenant;

    #[ORM\ManyToOne(targetEntity: Conversation::class)]
    #[ORM\JoinColumn(name: 'conversation_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Conversation $conversation = null;

    #[ORM\ManyToOne(targetEntity: AgentExecution::class, inversedBy: 'childExecutions')]
    #[ORM\JoinColumn(name: 'parent_execution_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?AgentExecution $parentExecution = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $agent;

    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $status = 'created';

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $duration = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $retryCount = 0;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $metadata = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $results = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $idempotencyKey = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $correlationId = null;

    #[ORM\OneToMany(mappedBy: 'parentExecution', targetEntity: AgentExecution::class, orphanRemoval: true)]
    private \Doctrine\Common\Collections\Collection $childExecutions;

    public function __construct()
    {
        $this->id = Ulid::generate();
        $this->createdAt = new \DateTimeImmutable();
        $this->childExecutions = new \Doctrine\Common\Collections\ArrayCollection();
    }

    // --- Getters and Setters ---

    public function getId(): ?string
    {
        return $this->id;
    }

    public function setId(string $id): static
    {
        $this->id = $id;
        return $this;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function setTenant(Tenant $tenant): static
    {
        $this->tenant = $tenant;
        return $this;
    }

    public function getConversation(): ?Conversation
    {
        return $this->conversation;
    }

    public function setConversation(?Conversation $conversation): static
    {
        $this->conversation = $conversation;
        return $this;
    }

    public function getParentExecution(): ?AgentExecution
    {
        return $this->parentExecution;
    }

    public function setParentExecution(?AgentExecution $parentExecution): static
    {
        $this->parentExecution = $parentExecution;
        return $this;
    }

    public function getAgent(): string
    {
        return $this->agent;
    }

    public function setAgent(string $agent): static
    {
        $this->agent = $agent;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTimeImmutable $startedAt): static
    {
        $this->startedAt = $startedAt;
        return $this;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTimeImmutable $completedAt): static
    {
        $this->completedAt = $completedAt;
        return $this;
    }

    public function getDuration(): ?int
    {
        return $this->duration;
    }

    public function setDuration(?int $duration): static
    {
        $this->duration = $duration;
        return $this;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function setError(?string $error): static
    {
        $this->error = $error;
        return $this;
    }

    public function getRetryCount(): int
    {
        return $this->retryCount;
    }

    public function setRetryCount(int $retryCount): static
    {
        $this->retryCount = $retryCount;
        return $this;
    }

    public function incrementRetryCount(): static
    {
        $this->retryCount++;
        return $this;
    }

    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    public function setMetadata(?array $metadata): static
    {
        $this->metadata = $metadata;
        return $this;
    }

    public function getResults(): ?array
    {
        return $this->results;
    }

    public function setResults(?array $results): static
    {
        $this->results = $results;
        return $this;
    }

    public function getIdempotencyKey(): ?string
    {
        return $this->idempotencyKey;
    }

    public function setIdempotencyKey(?string $idempotencyKey): static
    {
        $this->idempotencyKey = $idempotencyKey;
        return $this;
    }

    public function getCorrelationId(): ?string
    {
        return $this->correlationId;
    }

    public function setCorrelationId(?string $correlationId): static
    {
        $this->correlationId = $correlationId;
        return $this;
    }

    /**
     * @return \Doctrine\Common\Collections\Collection<int, AgentExecution>
     */
    public function getChildExecutions(): \Doctrine\Common\Collections\Collection
    {
        return $this->childExecutions;
    }

    public function addChildExecution(AgentExecution $childExecution): static
    {
        if (!$this->childExecutions->contains($childExecution)) {
            $this->childExecutions->add($childExecution);
            $childExecution->setParentExecution($this);
        }

        return $this;
    }

    public function removeChildExecution(AgentExecution $childExecution): static
    {
        if ($this->childExecutions->removeElement($childExecution)) {
            // set the owning side to null (unless already changed)
            if ($childExecution->getParentExecution() === $this) {
                $childExecution->setParentExecution(null);
            }
        }

        return $this;
    }

    // --- Custom Methods ---

    /**
     * Get the tenant ID for this execution.
     */
    public function getTenantId(): string
    {
        return $this->tenant->getId();
    }

    /**
     * Get the user ID for this execution.
     */
    public function getUserId(): string
    {
        return $this->user->getId();
    }

    /**
     * Check if this execution belongs to the given user.
     */
    public function belongsToUser(string $userId): bool
    {
        return $this->user->getId() === $userId;
    }

    /**
     * Check if this execution belongs to the given tenant.
     */
    public function belongsToTenant(string $tenantId): bool
    {
        return $this->tenant->getId() === $tenantId;
    }

    /**
     * Check if this execution is completed.
     */
    public function isCompleted(): bool
    {
        return in_array($this->status, ['completed', 'failed']);
    }

    /**
     * Check if this execution is running.
     */
    public function isRunning(): bool
    {
        return $this->status === 'running';
    }

    /**
     * Check if this execution is queued.
     */
    public function isQueued(): bool
    {
        return $this->status === 'queued';
    }

    /**
     * Check if this execution has children.
     */
    public function hasChildren(): bool
    {
        return !$this->childExecutions->isEmpty();
    }

    /**
     * Get the root execution (topmost parent).
     */
    public function getRootExecution(): AgentExecution
    {
        $parent = $this->parentExecution;
        if ($parent === null) {
            return $this;
        }
        return $parent->getRootExecution();
    }

    /**
     * Get the execution trace (all parent executions up to root).
     *
     * @return AgentExecution[]
     */
    public function getExecutionTrace(): array
    {
        $trace = [];
        $execution = $this;
        
        while ($execution !== null) {
            array_unshift($trace, $execution);
            $execution = $execution->parentExecution;
        }
        
        return $trace;
    }

    /**
     * Start this execution.
     */
    public function start(): static
    {
        $this->status = 'running';
        $this->startedAt = new \DateTimeImmutable();
        return $this;
    }

    /**
     * Complete this execution successfully.
     */
    public function complete(?array $results = null): static
    {
        $this->status = 'completed';
        $this->completedAt = new \DateTimeImmutable();
        
        if ($this->startedAt !== null) {
            $this->duration = $this->completedAt->getTimestamp() - $this->startedAt->getTimestamp();
        }
        
        if ($results !== null) {
            $this->results = $results;
        }
        
        return $this;
    }

    /**
     * Fail this execution.
     */
    public function fail(string $error): static
    {
        $this->status = 'failed';
        $this->completedAt = new \DateTimeImmutable();
        $this->error = $error;
        
        if ($this->startedAt !== null) {
            $this->duration = $this->completedAt->getTimestamp() - $this->startedAt->getTimestamp();
        }
        
        return $this;
    }

    /**
     * Queue this execution.
     */
    public function queue(): static
    {
        $this->status = 'queued';
        return $this;
    }

    /**
     * Convert to array for API responses.
     */
    public function toArray(bool $includeChildren = false): array
    {
        $data = [
            'id' => $this->id,
            'userId' => $this->user->getId(),
            'tenantId' => $this->tenant->getId(),
            'conversationId' => $this->conversation?->getId(),
            'parentExecutionId' => $this->parentExecution?->getId(),
            'agent' => $this->agent,
            'status' => $this->status,
            'createdAt' => $this->createdAt->format('c'),
            'startedAt' => $this->startedAt?->format('c'),
            'completedAt' => $this->completedAt?->format('c'),
            'duration' => $this->duration,
            'error' => $this->error,
            'retryCount' => $this->retryCount,
            'idempotencyKey' => $this->idempotencyKey,
            'correlationId' => $this->correlationId,
            'metadata' => $this->metadata,
            'results' => $this->results,
        ];

        if ($includeChildren) {
            $data['childExecutions'] = array_map(function(AgentExecution $child) {
                return $child->toArray();
            }, $this->childExecutions->toArray());
        }

        return $data;
    }

    public function __toString(): string
    {
        return sprintf(
            'Execution #%s: %s (%s)',
            substr($this->id, 0, 8),
            $this->agent,
            $this->status
        );
    }
}
