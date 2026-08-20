<?php

namespace App\Entity\Security;

use App\Repository\Security\UserSecretRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity(repositoryClass: UserSecretRepository::class)]
#[ORM\Table(name: 'user_secret')]
#[ORM\Index(name: 'idx_user_secret_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_user_secret_key', columns: ['secret_key'])]
#[ORM\UniqueConstraint(name: 'uniq_user_secret_key_user', columns: ['user_id', 'secret_key'])]
class UserSecret
{
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 255, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uid_generator')]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: 'App\Entity\Tenant\User', inversedBy: 'secrets')]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private \App\Entity\Tenant\User $user;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $secretKey;

    #[ORM\Column(type: Types::TEXT)]
    private string $encryptedValue;

    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $encryptionVersion = 'AES-256-GCM-v1';

    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $keyVersion = 'KEY_V1';

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $metadata = null;

    public function __construct()
    {
        $this->id = Ulid::generate();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
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

    public function getUser(): \App\Entity\Tenant\User
    {
        return $this->user;
    }

    public function setUser(\App\Entity\Tenant\User $user): static
    {
        $this->user = $user;
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

    public function getEncryptedValue(): string
    {
        return $this->encryptedValue;
    }

    public function setEncryptedValue(string $encryptedValue): static
    {
        $this->encryptedValue = $encryptedValue;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getEncryptionVersion(): string
    {
        return $this->encryptionVersion;
    }

    public function setEncryptionVersion(string $encryptionVersion): static
    {
        $this->encryptionVersion = $encryptionVersion;
        return $this;
    }

    public function getKeyVersion(): string
    {
        return $this->keyVersion;
    }

    public function setKeyVersion(string $keyVersion): static
    {
        $this->keyVersion = $keyVersion;
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

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
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

    // --- Custom Methods ---

    /**
     * Get the tenant ID from the user relationship.
     * This ensures tenant isolation at the database level.
     */
    public function getTenantId(): string
    {
        return $this->user->getTenant()->getId();
    }

    /**
     * Check if this secret belongs to the given user.
     */
    public function belongsToUser(\App\Entity\Tenant\User $user): bool
    {
        return $this->user->getId() === $user->getId();
    }

    /**
     * Check if this secret belongs to the given tenant.
     */
    public function belongsToTenant(string $tenantId): bool
    {
        return $this->getTenantId() === $tenantId;
    }
}
