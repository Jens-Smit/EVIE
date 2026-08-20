<?php

namespace App\Entity\AI;

use App\Entity\Tenant\User;
use App\Entity\Tenant\Tenant;
use App\Repository\AI\ConversationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity(repositoryClass: ConversationRepository::class)]
#[ORM\Table(name: 'conversation')]
#[ORM\Index(name: 'idx_conversation_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_conversation_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_conversation_status', columns: ['status'])]
#[ORM\Index(name: 'idx_conversation_created_at', columns: ['created_at'])]
class Conversation
{
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 255, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uid_generator')]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'conversations')]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false)]
    private Tenant $tenant;

    #[ORM\Column(type: Types::STRING, length: 500, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(type: Types::STRING, length: 50, options: ['default' => 'active'])]
    private string $status = 'active';

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastMessageAt = null;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $messageCount = 0;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $tokenCount = 0;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $metadata = null;

    #[ORM\OneToMany(mappedBy: 'conversation', targetEntity: Message::class, orphanRemoval: true, cascade: ['persist'])]
    private Collection $messages;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $contextWindowId = null;

    public function __construct()
    {
        $this->id = Ulid::generate();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->messages = new ArrayCollection();
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

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $this->title = $title;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        $this->updatedAt = new \DateTimeImmutable();
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

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function getLastMessageAt(): ?\DateTimeImmutable
    {
        return $this->lastMessageAt;
    }

    public function setLastMessageAt(?\DateTimeImmutable $lastMessageAt): static
    {
        $this->lastMessageAt = $lastMessageAt;
        return $this;
    }

    public function getMessageCount(): int
    {
        return $this->messageCount;
    }

    public function setMessageCount(int $messageCount): static
    {
        $this->messageCount = $messageCount;
        return $this;
    }

    public function incrementMessageCount(): static
    {
        $this->messageCount++;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getTokenCount(): int
    {
        return $this->tokenCount;
    }

    public function setTokenCount(int $tokenCount): static
    {
        $this->tokenCount = $tokenCount;
        return $this;
    }

    public function addTokenCount(int $tokens): static
    {
        $this->tokenCount += $tokens;
        $this->updatedAt = new \DateTimeImmutable();
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

    public function getContextWindowId(): ?string
    {
        return $this->contextWindowId;
    }

    public function setContextWindowId(?string $contextWindowId): static
    {
        $this->contextWindowId = $contextWindowId;
        return $this;
    }

    /**
     * @return Collection<int, Message>
     */
    public function getMessages(): Collection
    {
        return $this->messages;
    }

    public function addMessage(Message $message): static
    {
        if (!$this->messages->contains($message)) {
            $this->messages->add($message);
            $message->setConversation($this);
            $this->incrementMessageCount();
            $this->setLastMessageAt(new \DateTimeImmutable());
        }

        return $this;
    }

    public function removeMessage(Message $message): static
    {
        if ($this->messages->removeElement($message)) {
            // set the owning side to null (unless already changed)
            if ($message->getConversation() === $this) {
                $message->setConversation(null);
            }
        }

        return $this;
    }

    // --- Custom Methods ---

    /**
     * Get the tenant ID for this conversation.
     */
    public function getTenantId(): string
    {
        return $this->tenant->getId();
    }

    /**
     * Get the user ID for this conversation.
     */
    public function getUserId(): string
    {
        return $this->user->getId();
    }

    /**
     * Check if this conversation belongs to the given user.
     */
    public function belongsToUser(string $userId): bool
    {
        return $this->user->getId() === $userId;
    }

    /**
     * Check if this conversation belongs to the given tenant.
     */
    public function belongsToTenant(string $tenantId): bool
    {
        return $this->tenant->getId() === $tenantId;
    }

    /**
     * Get the first message in this conversation.
     */
    public function getFirstMessage(): ?Message
    {
        if ($this->messages->isEmpty()) {
            return null;
        }

        $messages = $this->messages->toArray();
        usort($messages, function(Message $a, Message $b) {
            return $a->getCreatedAt() <=> $b->getCreatedAt();
        });

        return $messages[0] ?? null;
    }

    /**
     * Get the last message in this conversation.
     */
    public function getLastMessage(): ?Message
    {
        if ($this->messages->isEmpty()) {
            return null;
        }

        $messages = $this->messages->toArray();
        usort($messages, function(Message $a, Message $b) {
            return $b->getCreatedAt() <=> $a->getCreatedAt();
        });

        return $messages[0] ?? null;
    }

    /**
     * Check if this conversation is active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Check if this conversation is archived.
     */
    public function isArchived(): bool
    {
        return $this->status === 'archived';
    }

    /**
     * Check if this conversation is paused.
     */
    public function isPaused(): bool
    {
        return $this->status === 'paused';
    }

    /**
     * Check if this conversation is deleted.
     */
    public function isDeleted(): bool
    {
        return $this->status === 'deleted';
    }

    /**
     * Archive this conversation.
     */
    public function archive(): static
    {
        $this->status = 'archived';
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    /**
     * Pause this conversation.
     */
    public function pause(): static
    {
        $this->status = 'paused';
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    /**
     * Continue this conversation.
     */
    public function continue(): static
    {
        $this->status = 'active';
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    /**
     * Delete this conversation (soft delete).
     */
    public function delete(): static
    {
        $this->status = 'deleted';
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    /**
     * Convert to array for API responses.
     */
    public function toArray(bool $includeMessages = false): array
    {
        $data = [
            'id' => $this->id,
            'userId' => $this->user->getId(),
            'tenantId' => $this->tenant->getId(),
            'title' => $this->title,
            'status' => $this->status,
            'createdAt' => $this->createdAt->format('c'),
            'updatedAt' => $this->updatedAt->format('c'),
            'lastMessageAt' => $this->lastMessageAt?->format('c'),
            'messageCount' => $this->messageCount,
            'tokenCount' => $this->tokenCount,
            'metadata' => $this->metadata,
        ];

        if ($includeMessages) {
            $data['messages'] = array_map(function(Message $message) {
                return $message->toArray();
            }, $this->messages->toArray());
        }

        return $data;
    }

    public function __toString(): string
    {
        return sprintf(
            'Conversation #%s: %s',
            substr($this->id, 0, 8),
            $this->title ?? '(Untitled)'
        );
    }
}
