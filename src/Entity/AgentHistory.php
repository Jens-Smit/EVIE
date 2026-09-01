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

    #[ORM\Column(type: Types::INTEGER, nullable: true, options: ['default' => 0])]
    private ?int $tokenUsage = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne(targetEntity: UserProfile::class, inversedBy: 'agentHistories')]
    #[ORM\JoinColumn(nullable: false)]
    private UserProfile $user;

    #[ORM\ManyToOne(targetEntity: SubAgent::class, inversedBy: 'history')]
    private ?SubAgent $subAgent = null;

    #[ORM\OneToMany(targetEntity: Document::class, mappedBy: 'agentHistory')]
    private iterable $documents;

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

    public function getTokenUsage(): ?int
    {
        return $this->tokenUsage;
    }

    public function setTokenUsage(?int $tokenUsage): static
    {
        $this->tokenUsage = $tokenUsage;
        return $this;
    }

    public function addTokenUsage(int $tokens): static
    {
        $this->tokenUsage = ($this->tokenUsage ?? 0) + $tokens;
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
        if (!isset($this->documents)) {
            $this->documents = [];
        }
        if (!in_array($document, $this->documents, true)) {
            $this->documents[] = $document;
            $document->setAgentHistory($this);
        }
        return $this;
    }

    public function removeDocument(Document $document): static
    {
        if (!isset($this->documents)) {
            return $this;
        }
        if (false !== $key = array_search($document, $this->documents, true)) {
            unset($this->documents[$key]);
            if ($document->getAgentHistory() === $this) {
                $document->setAgentHistory(null);
            }
        }
        return $this;
    }
}
