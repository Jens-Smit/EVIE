<?php

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Repository\SecretRepository::class)]
#[ORM\Table(name: 'secrets')]
#[ORM\Index(name: 'idx_secret_user_key', columns: ['user_identifier', 'key_name'])]
#[ORM\UniqueConstraint(name: 'uniq_secret_user_key', columns: ['user_identifier', 'key_name'])]
class Secret
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $userIdentifier;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $keyName;

    #[ORM\Column(type: Types::TEXT)]
    private string $encryptedValue;

    #[ORM\Column(type: Types::STRING, length: 100, nullable: true)]
    private ?string $scope = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $updatedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $lastUsedByTool = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getKeyName(): string
    {
        return $this->keyName;
    }

    public function setKeyName(string $keyName): static
    {
        $this->keyName = $keyName;
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

    public function getScope(): ?string
    {
        return $this->scope;
    }

    public function setScope(?string $scope): static
    {
        $this->scope = $scope;
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

    public function getLastUsedAt(): ?DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function setLastUsedAt(?DateTimeImmutable $lastUsedAt): static
    {
        $this->lastUsedAt = $lastUsedAt;
        return $this;
    }

    public function getLastUsedByTool(): ?string
    {
        return $this->lastUsedByTool;
    }

    public function setLastUsedByTool(?string $lastUsedByTool): static
    {
        $this->lastUsedByTool = $lastUsedByTool;
        return $this;
    }

    public function updateLastUsed(string $toolName): static
    {
        $this->setLastUsedAt(new DateTimeImmutable());
        $this->setLastUsedByTool($toolName);
        return $this;
    }
}
