<?php

namespace App\Entity;

use App\Repository\UserSecretRepository;
use App\Service\EncryptionService;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserSecretRepository::class)]
#[ORM\Table(name: 'user_secrets')]
class UserSecret
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\Column(length: 100)]
    private string $key;

    #[ORM\Column(type: 'text')]
    private string $encryptedValue;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $toolName = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $isActive = true;

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

    public function getKey(): string
    {
        return $this->key;
    }

    public function setKey(string $key): static
    {
        $this->key = $key;
        return $this;
    }

    public function getEncryptedValue(): string
    {
        return $this->encryptedValue;
    }

    public function setEncryptedValue(string $encryptedValue): static
    {
        $this->encryptedValue = $encryptedValue;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getToolName(): ?string
    {
        return $this->toolName;
    }

    public function setToolName(?string $toolName): static
    {
        $this->toolName = $toolName;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;
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
     * Gibt den entschlüsselten Wert zurück
     */
    public function getValue(EncryptionService $encryption): string
    {
        return $encryption->decrypt($this->encryptedValue);
    }

    /**
     * Setzt den verschlüsselten Wert
     */
    public function setValue(string $value, EncryptionService $encryption): static
    {
        $this->encryptedValue = $encryption->encrypt($value);
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
     * Gibt eine maskierte Version des Keys zurück (für Anzeigezwecke)
     */
    public function getMaskedKey(): string
    {
        if (strlen($this->key) <= 4) {
            return str_repeat('*', strlen($this->key));
        }
        
        return substr($this->key, 0, 2) . str_repeat('*', strlen($this->key) - 4) . substr($this->key, -2);
    }

    /**
     * Gibt an, ob dieses Secret für ein spezifisches Tool ist
     */
    public function isForTool(): bool
    {
        return $this->toolName !== null;
    }
}
