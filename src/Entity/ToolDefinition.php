<?php

namespace App\Entity;

use App\Repository\ToolDefinitionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ToolDefinitionRepository::class)]
#[ORM\Table(name: 'tool_definitions')]
class ToolDefinition
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(name: 'schema_definition', type: Types::JSON)]
    private array $schemaDefinition;

    #[ORM\Column(length: 255)]
    private string $description;

    #[ORM\Column(length: 50)]
    private string $status = 'pending'; // pending, approved, rejected, pending_approval

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $parameters = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $approvedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $rejectedAt = null;

    // NEUE FELDER FÜR LLM-BASIERTE TOOL-DEFINITION

    #[ORM\ManyToOne(targetEntity: ToolCategory::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?ToolCategory $category = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $complexity = null; // low, medium, high

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $dependencies = null; // Abhängigkeiten wie ['http_client', 'firecrawl']

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $metadata = null; // Metadaten für Wiederverwendung

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
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

    public function getSchema(): array
    {
        return $this->schemaDefinition;
    }

    public function setSchema(array $schema): static
    {
        $this->schemaDefinition = $schema;
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

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getParameters(): ?array
    {
        return $this->parameters;
    }

    public function setParameters(?array $parameters): static
    {
        $this->parameters = $parameters;
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

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function getApprovedAt(): ?\DateTimeImmutable
    {
        return $this->approvedAt;
    }

    public function setApprovedAt(?\DateTimeImmutable $approvedAt): static
    {
        $this->approvedAt = $approvedAt;
        return $this;
    }

    public function getRejectedAt(): ?\DateTimeImmutable
    {
        return $this->rejectedAt;
    }

    public function setRejectedAt(?\DateTimeImmutable $rejectedAt): static
    {
        $this->rejectedAt = $rejectedAt;
        return $this;
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isPending(): bool
    {
        return in_array($this->status, ['pending', 'pending_approval']);
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    // NEUE GETTER/SETTER FÜR DIE NEUEN FELDER

    public function getCategory(): ?ToolCategory
    {
        return $this->category;
    }

    public function setCategory(?ToolCategory $category): static
    {
        $this->category = $category;
        return $this;
    }

    public function getComplexity(): ?string
    {
        return $this->complexity;
    }

    public function setComplexity(?string $complexity): static
    {
        $this->complexity = $complexity;
        return $this;
    }

    public function getDependencies(): ?array
    {
        return $this->dependencies;
    }

    public function setDependencies(?array $dependencies): static
    {
        $this->dependencies = $dependencies;
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
}
