<?php

namespace App\Entity;

use App\Repository\SecretRequestRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SecretRequestRepository::class)]
#[ORM\Table(name: 'secret_requests')]
class SecretRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ToolDefinition::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?ToolDefinition $tool = null;

    #[ORM\Column(length: 100)]
    private string $secretKey;

    #[ORM\Column(type: 'text')]
    private string $description;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $isRequired = true;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTool(): ?ToolDefinition
    {
        return $this->tool;
    }

    public function setTool(?ToolDefinition $tool): static
    {
        $this->tool = $tool;
        return $this;
    }

    public function getSecretKey(): string
    {
        return $this->secretKey;
    }

    public function setSecretKey(string $secretKey): static
    {
        $this->secretKey = $secretKey;
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

    public function isRequired(): bool
    {
        return $this->isRequired;
    }

    public function setIsRequired(bool $isRequired): static
    {
        $this->isRequired = $isRequired;
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

    /**
     * Gibt eine User-freundliche Nachricht zurück
     */
    public function getUserMessage(): string
    {
        return sprintf(
            "Das Tool '%s' benötigt einen **%s**. %s",
            $this->tool?->getName() ?? 'Unbekanntes Tool',
            $this->secretKey,
            $this->description
        );
    }

    /**
     * Gibt an, ob dieses Secret bereits für einen User existiert
     */
    public function isFulfilledForUser(User $user, UserSecretRepository $secretRepo): bool
    {
        return $secretRepo->findOneBy([
            'user' => $user,
            'key' => $this->secretKey,
            'isActive' => true
        ]) !== null;
    }
}
