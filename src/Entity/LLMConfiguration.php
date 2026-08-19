<?php

namespace App\Entity;

use App\Repository\LLMConfigurationRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: LLMConfigurationRepository::class)]
#[ORM\Table(name: 'llm_configurations')]
class LLMConfiguration
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\Column(length: 50)]
    private string $provider;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $customProviderName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $customApiUrl = null;

    #[ORM\Column(length: 100)]
    private string $model;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $apiKey = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isDefault = false;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function setProvider(string $provider): static
    {
        $this->provider = $provider;
        return $this;
    }

    public function getCustomProviderName(): ?string
    {
        return $this->customProviderName;
    }

    public function setCustomProviderName(?string $customProviderName): static
    {
        $this->customProviderName = $customProviderName;
        return $this;
    }

    public function getCustomApiUrl(): ?string
    {
        return $this->customApiUrl;
    }

    public function setCustomApiUrl(?string $customApiUrl): static
    {
        $this->customApiUrl = $customApiUrl;
        return $this;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function setModel(string $model): static
    {
        $this->model = $model;
        return $this;
    }

    public function getApiKey(): ?string
    {
        return $this->apiKey;
    }

    public function setApiKey(?string $apiKey): static
    {
        $this->apiKey = $apiKey;
        return $this;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function setIsDefault(bool $isDefault): static
    {
        $this->isDefault = $isDefault;
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

    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    /**
     * Wird aufgerufen, bevor die Entity gespeichert wird
     */
    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function updateTimestamps(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }

    /**
     * Gibt an, ob dies eine benutzerdefinierte Konfiguration ist
     */
    public function isCustom(): bool
    {
        return $this->provider === 'custom';
    }

    /**
     * Gibt die vollständige API-URL für benutzerdefinierte Provider zurück
     */
    public function getFullApiUrl(): string
    {
        if ($this->isCustom() && $this->customApiUrl) {
            return $this->customApiUrl;
        }
        
        return match ($this->provider) {
            'mistral' => 'https://api.mistral.ai/v1',
            'openai' => 'https://api.openai.com/v1',
            'google' => 'https://generativelanguage.googleapis.com/v1beta',
            'anthropic' => 'https://api.anthropic.com/v1',
            default => 'https://api.mistral.ai/v1',
        };
    }
}
