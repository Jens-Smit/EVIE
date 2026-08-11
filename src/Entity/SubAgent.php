<?php

namespace App\Entity;

use App\Repository\SubAgentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SubAgentRepository::class)]
class SubAgent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(type: Types::TEXT)]
    private string $description;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\OneToMany(targetEntity: AgentHistory::class, mappedBy: 'subAgent')]
    private Collection $history;

    #[ORM\ManyToOne(targetEntity: UserProfile::class, inversedBy: 'subAgents')]
    #[ORM\JoinColumn(nullable: false)]
    private UserProfile $user;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $capabilities = [];

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $status = 'active';

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->history = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;
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

    /**
     * @return Collection<int, AgentHistory>
     */
    public function getHistory(): Collection
    {
        return $this->history;
    }

    public function addHistory(AgentHistory $history): static
    {
        if (!$this->history->contains($history)) {
            $this->history->add($history);
            $history->setSubAgent($this);
        }
        return $this;
    }

    public function removeHistory(AgentHistory $history): static
    {
        if ($this->history->removeElement($history)) {
            if ($history->getSubAgent() === $this) {
                $history->setSubAgent(null);
            }
        }
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

    public function getCapabilities(): ?array
    {
        return $this->capabilities;
    }

    public function setCapabilities(?array $capabilities): static
    {
        $this->capabilities = $capabilities;
        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(?string $status): static
    {
        $this->status = $status;
        return $this;
    }
}
