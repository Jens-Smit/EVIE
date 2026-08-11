<?php

namespace App\Entity;

use App\Repository\UserProfileRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserProfileRepository::class)]
class UserProfile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 255, unique: true)]
    private string $userIdentifier;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $preferences = [];

    #[ORM\OneToMany(targetEntity: AgentHistory::class, mappedBy: 'user')]
    private Collection $agentHistories;

    #[ORM\OneToMany(targetEntity: Document::class, mappedBy: 'user')]
    private Collection $documents;

    #[ORM\OneToMany(targetEntity: SubAgent::class, mappedBy: 'user')]
    private Collection $subAgents;

    public function __construct()
    {
        $this->agentHistories = new ArrayCollection();
        $this->documents = new ArrayCollection();
        $this->subAgents = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getUserIdentifier(): string
    {
        return $this->userIdentifier;
    }

    public function setUserIdentifier(string $userIdentifier): static
    {
        $this->userIdentifier = $userIdentifier;
        return $this;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;
        return $this;
    }

    public function getPreferences(): ?array
    {
        return $this->preferences;
    }

    public function setPreferences(?array $preferences): static
    {
        $this->preferences = $preferences;
        return $this;
    }

    /**
     * @return Collection<int, AgentHistory>
     */
    public function getAgentHistories(): Collection
    {
        return $this->agentHistories;
    }

    public function addAgentHistory(AgentHistory $agentHistory): static
    {
        if (!$this->agentHistories->contains($agentHistory)) {
            $this->agentHistories->add($agentHistory);
            $agentHistory->setUser($this);
        }
        return $this;
    }

    public function removeAgentHistory(AgentHistory $agentHistory): static
    {
        if ($this->agentHistories->removeElement($agentHistory)) {
            if ($agentHistory->getUser() === $this) {
                $agentHistory->setUser(null);
            }
        }
        return $this;
    }

    /**
     * @return Collection<int, Document>
     */
    public function getDocuments(): Collection
    {
        return $this->documents;
    }

    public function addDocument(Document $document): static
    {
        if (!$this->documents->contains($document)) {
            $this->documents->add($document);
            $document->setUser($this);
        }
        return $this;
    }

    public function removeDocument(Document $document): static
    {
        if ($this->documents->removeElement($document)) {
            if ($document->getUser() === $this) {
                $document->setUser(null);
            }
        }
        return $this;
    }

    /**
     * @return Collection<int, SubAgent>
     */
    public function getSubAgents(): Collection
    {
        return $this->subAgents;
    }

    public function addSubAgent(SubAgent $subAgent): static
    {
        if (!$this->subAgents->contains($subAgent)) {
            $this->subAgents->add($subAgent);
            $subAgent->setUser($this);
        }
        return $this;
    }

    public function removeSubAgent(SubAgent $subAgent): static
    {
        if ($this->subAgents->removeElement($subAgent)) {
            if ($subAgent->getUser() === $this) {
                $subAgent->setUser(null);
            }
        }
        return $this;
    }
}
