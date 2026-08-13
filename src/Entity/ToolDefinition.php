<?php

namespace AppEntity;

use AppRepositoryToolDefinitionRepository;
use DoctrineDBALTypesTypes;
use DoctrineORMMapping as ORM;

#[ORMEntity(repositoryClass: ToolDefinitionRepository::class)]
#[ORMTable(name: 'tool_definitions')]
class ToolDefinition
{
    #[ORMId]
    #[ORMGeneratedValue]
    #[ORMColumn]
    private ?int $id = null;

    #[ORMColumn(type: Types::STRING, length: 255)]
    private ?string $name = null;

    #[ORMColumn(type: Types::TEXT)]
    private ?string $description = null;

    #[ORMColumn(type: Types::JSON)]
    private array $schema = [];

    #[ORMColumn(type: Types::STRING, length: 255)]
    private ?string $category = null;

    #[ORMColumn(type: Types::INTEGER, options: ['default' => 1])]
    private ?int $complexity = 1;

    #[ORMColumn(type: Types::JSON, nullable: true)]
    private ?array $dependencies = null;

    #[ORMColumn(type: Types::STRING, length: 50, options: ['default' => 'low'])]
    private ?string $securityLevel = 'low';

    #[ORMColumn(type: Types::BOOLEAN, options: ['default' => false])]
    private ?bool $requiresHitl = false;

    #[ORMColumn(type: Types::JSON, nullable: true)]
    private ?array $metadata = null;

    #[ORMColumn(type: Types::STRING, length: 50, options: ['default' => 'pending'])]
    private ?string $status = 'pending';

    #[ORMColumn(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeImmutable $createdAt = null;

    #[ORMColumn(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $updatedAt = null;

    // ========== NEUE FELDER FÜR PHASE 2 ==========

    #[ORMColumn(type: Types::STRING, length: 50, nullable: true)]
    private ?string $executorType = null;

    #[ORMColumn(type: Types::JSON, nullable: true)]
    private ?array $executorConfig = null;

    #[ORMColumn(type: Types::JSON, nullable: true)]
    private ?array $securityPolicy = null;

    #[ORMColumn(type: Types::JSON, nullable: true)]
    private ?array $hitlPolicy = null;

    #[ORMColumn(type: Types::STRING, length: 50, nullable: true)]
    private ?string $version = '1.0';
    // ================================================

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }

    // Getter und Setter für bestehende Felder

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
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

    public function getSchema(): array
    {
        return $this->schema;
    }

    public function setSchema(array $schema): static
    {
        $this->schema = $schema;
        return $this;
    }

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function setCategory(?string $category): static
    {
        $this->category = $category;
        return $this;
    }

    public function getComplexity(): ?int
    {
        return $this->complexity;
    }

    public function setComplexity(int $complexity): static
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

    public function getSecurityLevel(): ?string
    {
        return $this->securityLevel;
    }

    public function setSecurityLevel(?string $securityLevel): static
    {
        $this->securityLevel = $securityLevel;
        return $this;
    }

    public function getRequiresHitl(): ?bool
    {
        return $this->requiresHitl;
    }

    public function setRequiresHitl(bool $requiresHitl): static
    {
        $this->requiresHitl = $requiresHitl;
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

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
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

    // ========== GETTER/SETTER FÜR NEUE FELDER ==========

    public function getExecutorType(): ?string
    {
        return $this->executorType;
    }

    public function setExecutorType(?string $executorType): static
    {
        $this->executorType = $executorType;
        return $this;
    }

    public function getExecutorConfig(): ?array
    {
        return $this->executorConfig;
    }

    public function setExecutorConfig(?array $executorConfig): static
    {
        $this->executorConfig = $executorConfig;
        return $this;
    }

    public function getSecurityPolicy(): ?array
    {
        return $this->securityPolicy;
    }

    public function setSecurityPolicy(?array $securityPolicy): static
    {
        $this->securityPolicy = $securityPolicy;
        return $this;
    }

    public function getHitlPolicy(): ?array
    {
        return $this->hitlPolicy;
    }

    public function setHitlPolicy(?array $hitlPolicy): static
    {
        $this->hitlPolicy = $hitlPolicy;
        return $this;
    }

    public function getVersion(): ?string
    {
        return $this->version;
    }

    public function setVersion(?string $version): static
    {
        $this->version = $version;
        return $this;
    }
    // ================================================

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'schema' => $this->schema,
            'category' => $this->category,
            'complexity' => $this->complexity,
            'dependencies' => $this->dependencies,
            'securityLevel' => $this->securityLevel,
            'requiresHitl' => $this->requiresHitl,
            'metadata' => $this->metadata,
            'status' => $this->status,
            'executorType' => $this->executorType,
            'executorConfig' => $this->executorConfig,
            'securityPolicy' => $this->securityPolicy,
            'hitlPolicy' => $this->hitlPolicy,
            'version' => $this->version,
            'createdAt' => $this->createdAt?->format('Y-m-d H:i:s'),
            'updatedAt' => $this->updatedAt?->format('Y-m-d H:i:s'),
        ];
    }
}
