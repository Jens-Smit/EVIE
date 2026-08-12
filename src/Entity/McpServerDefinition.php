<?php
// src/Entity/McpServerDefinition.php

namespace App\Entity;

use App\Repository\McpServerDefinitionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Entity für MCP-Server-Definitionen.
 * Ermöglicht die dynamische Konfiguration von MCP-Servern aus der Datenbank.
 */
#[ORM\Entity(repositoryClass: McpServerDefinitionRepository::class)]
#[ORM\Table(name: 'ai_mcp_server_definitions')]
class McpServerDefinition
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\Column(type: 'string', length: 255, unique: true)]
    private string $name;

    #[ORM\Column(type: 'string', length: 255)]
    private string $type; // filesystem, playwright, github, custom, etc.

    #[ORM\Column(type: 'text')]
    private string $description;

    #[ORM\Column(type: 'json')]
    private array $configuration = []; // URL, Token, Command, etc.

    #[ORM\Column(type: 'boolean')]
    private bool $isActive = true;

    #[ORM\Column(type: 'json')]
    private array $allowedTools = []; // Whitelist für Tools

    #[ORM\Column(type: 'json')]
    private array $blockedResources = []; // Blocklist für Ressourcen

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $createdBy = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
    }

    // Getter & Setter

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;
        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function getConfiguration(): array
    {
        return $this->configuration;
    }

    public function setConfiguration(array $configuration): self
    {
        $this->configuration = $configuration;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function getAllowedTools(): array
    {
        return $this->allowedTools;
    }

    public function setAllowedTools(array $allowedTools): self
    {
        $this->allowedTools = $allowedTools;
        return $this;
    }

    public function getBlockedResources(): array
    {
        return $this->blockedResources;
    }

    public function setBlockedResources(array $blockedResources): self
    {
        $this->blockedResources = $blockedResources;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): self
    {
        $this->createdBy = $createdBy;
        return $this;
    }

    /**
     * Gibt die Konfiguration für den MCP-Server als Array zurück.
     * Format: [transport, command/url, arguments, etc.]
     */
    public function getMcpConfiguration(): array
    {
        return array_merge(
            ['name' => $this->name, 'type' => $this->type],
            $this->configuration
        );
    }

    /**
     * Prüft, ob ein Tool erlaubt ist.
     */
    public function isToolAllowed(string $toolName): bool
    {
        if (empty($this->allowedTools)) {
            return true; // Keine Whitelist = alle Tools erlaubt
        }

        return in_array($toolName, $this->allowedTools);
    }

    /**
     * Prüft, ob eine Ressource blockiert ist.
     */
    public function isResourceBlocked(string $resource): bool
    {
        if (empty($this->blockedResources)) {
            return false; // Keine Blocklist = keine Ressourcen blockiert
        }

        foreach ($this->blockedResources as $pattern) {
            if (fnmatch($pattern, $resource)) {
                return true;
            }
        }

        return false;
    }
}
