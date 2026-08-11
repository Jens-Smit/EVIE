<?php

namespace App\Entity;

use App\Repository\DecisionLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DecisionLogRepository::class)]
class DecisionLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $decision;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $context = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $status = 'pending';

    #[ORM\ManyToOne(targetEntity: UserProfile::class)]
    #[ORM\JoinColumn(nullable: false)]
    private UserProfile $user;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDecision(): string
    {
        return $this->decision;
    }

    public function setDecision(string $decision): static
    {
        $this->decision = $decision;
        return $this;
    }

    public function getContext(): ?string
    {
        return $this->context;
    }

    public function setContext(?string $context): static
    {
        $this->context = $context;
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

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(?string $status): static
    {
        $this->status = $status;
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
}
