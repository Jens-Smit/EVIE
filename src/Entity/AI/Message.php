<?php

namespace App\Entity\AI;

use App\Entity\Tenant\User;
use App\Repository\AI\MessageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity(repositoryClass: MessageRepository::class)]
#[ORM\Table(name: 'message')]
#[ORM\Index(name: 'idx_message_conversation', columns: ['conversation_id'])]
#[ORM\Index(name: 'idx_message_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_message_created_at', columns: ['created_at'])]
#[ORM\Index(name: 'idx_message_role', columns: ['role'])]
class Message
{
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 255, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uid_generator')]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Conversation::class, inversedBy: 'messages')]
    #[ORM\JoinColumn(name: 'conversation_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Conversation $conversation;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false)]
    private User $user;

    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $role;

    #[ORM\Column(type: Types::TEXT)]
    private string $content;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $tokenCount = 0;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $metadata = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $attachments = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $parentMessageId = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $executionId = null;

    public function __construct()
    {
        $this->id = Ulid::generate();
        $this->createdAt = new \DateTimeImmutable();
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

    public function getConversation(): Conversation
    {
        return $this->conversation;
    }

    public function setConversation(Conversation $conversation): static
    {
        $this->conversation = $conversation;
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

    public function getRole(): string
    {
        return $this->role;
    }

    public function setRole(string $role): static
    {
        $this->role = $role;
        return $this;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): static
    {
        $this->content = $content;
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

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
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

    public function getAttachments(): ?array
    {
        return $this->attachments;
    }

    public function setAttachments(?array $attachments): static
    {
        $this->attachments = $attachments;
        return $this;
    }

    public function getParentMessageId(): ?string
    {
        return $this->parentMessageId;
    }

    public function setParentMessageId(?string $parentMessageId): static
    {
        $this->parentMessageId = $parentMessageId;
        return $this;
    }

    public function getExecutionId(): ?string
    {
        return $this->executionId;
    }

    public function setExecutionId(?string $executionId): static
    {
        $this->executionId = $executionId;
        return $this;
    }

    // --- Custom Methods ---

    /**
     * Get the tenant ID for this message.
     */
    public function getTenantId(): string
    {
        return $this->conversation->getTenantId();
    }

    /**
     * Get the conversation ID for this message.
     */
    public function getConversationId(): string
    {
        return $this->conversation->getId();
    }

    /**
     * Get the user ID for this message.
     */
    public function getUserId(): string
    {
        return $this->user->getId();
    }

    /**
     * Check if this message belongs to the given user.
     */
    public function belongsToUser(string $userId): bool
    {
        return $this->user->getId() === $userId;
    }

    /**
     * Check if this message belongs to the given tenant.
     */
    public function belongsToTenant(string $tenantId): bool
    {
        return $this->conversation->belongsToTenant($tenantId);
    }

    /**
     * Check if this message is from the system/assistant.
     */
    public function isFromAssistant(): bool
    {
        return in_array($this->role, ['assistant', 'system', 'tool']);
    }

    /**
     * Check if this message is from the user.
     */
    public function isFromUser(): bool
    {
        return $this->role === 'user';
    }

    /**
     * Convert to array for API responses.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'conversationId' => $this->conversation->getId(),
            'userId' => $this->user->getId(),
            'role' => $this->role,
            'content' => $this->content,
            'createdAt' => $this->createdAt->format('c'),
            'updatedAt' => $this->updatedAt?->format('c'),
            'tokenCount' => $this->tokenCount,
            'metadata' => $this->metadata,
            'attachments' => $this->attachments,
            'parentMessageId' => $this->parentMessageId,
            'executionId' => $this->executionId,
        ];
    }

    public function __toString(): string
    {
        return sprintf(
            'Message #%s [%s]: %s',
            substr($this->id, 0, 8),
            $this->role,
            substr($this->content, 0, 50) . (strlen($this->content) > 50 ? '...' : '')
        );
    }
}
