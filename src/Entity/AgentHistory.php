<?php

namespace App\Entity;

use App\Repository\AgentHistoryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AgentHistoryRepository::class)]
#[ORM\Table(name: 'agent_history')]
class AgentHistory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $agentName;

    #[ORM\Column(type: Types::JSON)]
    private array $action;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $input = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $output = null;

    #[ORM\Column(length: 50)]
    private string $status; // success, failed, pending

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $executedAt;

    #[ORM\ManyToOne(targetEntity: UserProfile::class)]
    #[ORM\JoinColumn(nullable: false)]
    private UserProfile $userProfile;

    public function __construct()
    {
        $this->executedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAgentName(): string
    {
        return $this->agentName;
    }

    public function setAgentName(string $agentName): static
    {
        $this->agentName = $agentName;
        return $this;
    }

    public function getAction(): array
    {
        return $this->action;
    }

    public function setAction(array $action): static
    {
        $this->action = $action;
        return $this;
    }

    public function getInput(): ?array
    {
        return $this->input;
    }

    public function setInput(?array $input): static
    {
        $this->input = $input;
        return $this;
    }

    public function getOutput(): ?array
    {
        return $this->output;
    }

    public function setOutput(?array $output): static
    {
        $this->output = $output;
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

    public function getExecutedAt(): \DateTimeImmutable
    {
        return $this->executedAt;
    }

    public function setExecutedAt(\DateTimeImmutable $executedAt): static
    {
        $this->executedAt = $executedAt;
        return $this;
    }

    public function getUserProfile(): UserProfile
    {
        return $this->userProfile;
    }

    public function setUserProfile(UserProfile $userProfile): static
    {
        $this->userProfile = $userProfile;
        return $this;
    }
}
