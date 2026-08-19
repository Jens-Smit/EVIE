<?php

namespace App\Entity;

use App\Repository\AgentHistoryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AgentHistoryRepository::class)]
class AgentHistory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $action;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $details = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne(targetEntity: UserProfile::class, inversedBy: 'agentHistories')]
    #[ORM\JoinColumn(nullable: false)]
    private UserProfile $user;

    #[ORM\ManyToOne(targetEntity: SubAgent::class, inversedBy: 'history')]
    private ?SubAgent $subAgent = null;

    #[ORM\OneToMany(targetEntity: Document::class, mappedBy: 'agentHistory')]
    private iterable $documents;

    // NEU: Conversation Support für v0.9.5
    
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $conversationId = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $conversationOrder = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isUserMessage = false;

    #[ORM\Column(length: 50)]
    private string $messageType = 'text';

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $agentName = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $userIdentifier = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $isSuccess = true;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $parentMessageId = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $metadata = null;

    // NEU: Content Feld für Chat-Nachrichten
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $content = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function setAction(string $action): static
    {
        $this->action = $action;
        return $this;
    }

    public function getDetails(): ?string
    {
        return $this->details;
    }

    public function setDetails(?string $details): static
    {
        $this->details = $details;
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

    public function getUser(): UserProfile
    {
        return $this->user;
    }

    public function setUser(UserProfile $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getSubAgent(): ?SubAgent
    {
        return $this->subAgent;
    }

    public function setSubAgent(?SubAgent $subAgent): static
    {
        $this->subAgent = $subAgent;
        return $this;
    }

    public function getDocuments(): iterable
    {
        return $this->documents;
    }

    public function addDocument(Document $document): static
    {
        if (!$this->documents->contains($document)) {
            $this->documents[] = $document;
            $document->setAgentHistory($this);
        }
        return $this;
    }

    public function removeDocument(Document $document): static
    {
        if ($this->documents->removeElement($document)) {
            if ($document->getAgentHistory() === $this) {
                $document->setAgentHistory(null);
            }
        }
        return $this;
    }

    // NEUE GETTER UND SETTER FÜR CONVERSATION SUPPORT

    public function getConversationId(): ?string
    {
        return $this->conversationId;
    }

    public function setConversationId(?string $conversationId): static
    {
        $this->conversationId = $conversationId;
        return $this;
    }

    public function getConversationOrder(): ?int
    {
        return $this->conversationOrder;
    }

    public function setConversationOrder(?int $conversationOrder): static
    {
        $this->conversationOrder = $conversationOrder;
        return $this;
    }

    public function isUserMessage(): bool
    {
        return $this->isUserMessage;
    }

    public function setIsUserMessage(bool $isUserMessage): static
    {
        $this->isUserMessage = $isUserMessage;
        return $this;
    }

    public function getMessageType(): string
    {
        return $this->messageType;
    }

    public function setMessageType(string $messageType): static
    {
        $this->messageType = $messageType;
        return $this;
    }

    public function getAgentName(): ?string
    {
        return $this->agentName;
    }

    public function setAgentName(?string $agentName): static
    {
        $this->agentName = $agentName;
        return $this;
    }

    public function getUserIdentifier(): ?string
    {
        return $this->userIdentifier;
    }

    public function setUserIdentifier(?string $userIdentifier): static
    {
        $this->userIdentifier = $userIdentifier;
        return $this;
    }

    public function isSuccess(): bool
    {
        return $this->isSuccess;
    }

    public function setIsSuccess(bool $isSuccess): static
    {
        $this->isSuccess = $isSuccess;
        return $this;
    }

    public function getParentMessageId(): ?int
    {
        return $this->parentMessageId;
    }

    public function setParentMessageId(?int $parentMessageId): static
    {
        $this->parentMessageId = $parentMessageId;
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

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function setContent(?string $content): static
    {
        $this->content = $content;
        return $this;
    }

    /**
     * Gibt an, ob diese Nachricht Teil einer Conversation ist
     */
    public function isPartOfConversation(): bool
    {
        return $this->conversationId !== null;
    }

    /**
     * Gibt an, ob diese Nachricht eine System-Nachricht ist
     */
    public function isSystemMessage(): bool
    {
        return $this->messageType === 'system';
    }

    /**
     * Gibt an, ob diese Nachricht eine Tool-Nachricht ist
     */
    public function isToolMessage(): bool
    {
        return str_starts_with($this->messageType, 'tool_');
    }

    /**
     * Gibt an, ob diese Nachricht eine Benachrichtigung ist
     */
    public function isNotification(): bool
    {
        return $this->messageType === 'notification';
    }

    /**
     * Gibt eine Zusammenfassung der Nachricht für Anzeigezwecke zurück
     */
    public function getSummary(): string
    {
        if ($this->content) {
            return substr($this->content, 0, 100) . (strlen($this->content) > 100 ? '...' : '');
        }
        
        if ($this->details) {
            return substr($this->details, 0, 100) . (strlen($this->details) > 100 ? '...' : '');
        }
        
        return $this->action;
    }
}
