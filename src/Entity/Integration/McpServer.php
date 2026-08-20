<?php

namespace App\Entity\Integration;

use App\Entity\Tenant\Tenant;
use App\Repository\Integration\McpServerRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity(repositoryClass: McpServerRepository::class)]
#[ORM\Table(name: 'mcp_server')]
#[ORM\Index(name: 'idx_mcp_server_identifier', columns: ['identifier'])]
#[ORM\Index(name: 'idx_mcp_server_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_mcp_server_enabled', columns: ['is_enabled'])]
#[ORM\Index(name: 'idx_mcp_server_connected', columns: ['is_connected'])]
#[ORM\UniqueConstraint(name: 'uniq_mcp_server_identifier_tenant', columns: ['tenant_id', 'identifier'])]
class McpServer
{
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 255, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uid_generator')]
    private ?string $id = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $identifier;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::STRING, length: 2048)]
    private string $url;

    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $type = 'server';

    #[ORM\Column(type: Types::JSON)]
    private array $tools = [];

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $resources = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $configuration = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isEnabled = true;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isConnected = false;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastConnectedAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastErrorAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lastError = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false)]
    private Tenant $tenant;

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

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function setIdentifier(string $identifier): static
    {
        $this->identifier = $identifier;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setUrl(string $url): static
    {
        $this->url = $url;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getTools(): array
    {
        return $this->tools;
    }

    public function setTools(array $tools): static
    {
        $this->tools = $tools;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function addTool(array $tool): static
    {
        $this->tools[] = $tool;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function removeTool(string $toolName): static
    {
        $this->tools = array_filter($this->tools, function($tool) use ($toolName) {
            return $tool['name'] !== $toolName;
        });
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getResources(): ?array
    {
        return $this->resources;
    }

    public function setResources(?array $resources): static
    {
        $this->resources = $resources;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function addResource(array $resource): static
    {
        if ($this->resources === null) {
            $this->resources = [];
        }
        $this->resources[] = $resource;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function removeResource(string $resourceName): static
    {
        if ($this->resources !== null) {
            $this->resources = array_filter($this->resources, function($resource) use ($resourceName) {
                return $resource['name'] !== $resourceName;
            });
        }
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getConfiguration(): ?array
    {
        return $this->configuration;
    }

    public function setConfiguration(?array $configuration): static
    {
        $this->configuration = $configuration;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->isEnabled;
    }

    public function setIsEnabled(bool $isEnabled): static
    {
        $this->isEnabled = $isEnabled;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function enable(): static
    {
        $this->isEnabled = true;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function disable(): static
    {
        $this->isEnabled = false;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function isConnected(): bool
    {
        return $this->isConnected;
    }

    public function setIsConnected(bool $isConnected): static
    {
        $this->isConnected = $isConnected;
        $this->updatedAt = new \DateTimeImmutable();
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

    public function getLastConnectedAt(): ?\DateTimeImmutable
    {
        return $this->lastConnectedAt;
    }

    public function setLastConnectedAt(?\DateTimeImmutable $lastConnectedAt): static
    {
        $this->lastConnectedAt = $lastConnectedAt;
        return $this;
    }

    public function getLastErrorAt(): ?\DateTimeImmutable
    {
        return $this->lastErrorAt;
    }

    public function setLastErrorAt(?\DateTimeImmutable $lastErrorAt): static
    {
        $this->lastErrorAt = $lastErrorAt;
        return $this;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function setLastError(?string $lastError): static
    {
        $this->lastError = $lastError;
        return $this;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function setTenant(Tenant $tenant): static
    {
        $this->tenant = $tenant;
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
     * Get the tenant ID for this MCP server.
     */
    public function getTenantId(): string
    {
        return $this->tenant->getId();
    }

    /**
     * Check if this MCP server belongs to the given tenant.
     */
    public function belongsToTenant(string $tenantId): bool
    {
        return $this->tenant->getId() === $tenantId;
    }

    /**
     * Get a tool by name.
     */
    public function getTool(string $toolName): ?array
    {
        foreach ($this->tools as $tool) {
            if ($tool['name'] === $toolName) {
                return $tool;
            }
        }
        return null;
    }

    /**
     * Check if this MCP server has a tool.
     */
    public function hasTool(string $toolName): bool
    {
        return $this->getTool($toolName) !== null;
    }

    /**
     * Get a resource by name.
     */
    public function getResource(string $resourceName): ?array
    {
        if ($this->resources === null) {
            return null;
        }

        foreach ($this->resources as $resource) {
            if ($resource['name'] === $resourceName) {
                return $resource;
            }
        }
        return null;
    }

    /**
     * Check if this MCP server has a resource.
     */
    public function hasResource(string $resourceName): bool
    {
        return $this->getResource($resourceName) !== null;
    }

    /**
     * Check if this MCP server is ready to use.
     */
    public function isReady(): bool
    {
        return $this->isEnabled && $this->isConnected;
    }

    /**
     * Get the connection status.
     */
    public function getConnectionStatus(): string
    {
        if (!$this->isEnabled) {
            return 'disabled';
        }

        if (!$this->isConnected) {
            return 'disconnected';
        }

        return 'connected';
    }

    /**
     * Convert to array for API responses.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'identifier' => $this->identifier,
            'name' => $this->name,
            'description' => $this->description,
            'url' => $this->url,
            'type' => $this->type,
            'tools' => $this->tools,
            'resources' => $this->resources,
            'configuration' => $this->configuration,
            'isEnabled' => $this->isEnabled,
            'isConnected' => $this->isConnected,
            'createdAt' => $this->createdAt->format('c'),
            'updatedAt' => $this->updatedAt->format('c'),
            'lastConnectedAt' => $this->lastConnectedAt?->format('c'),
            'lastErrorAt' => $this->lastErrorAt?->format('c'),
            'lastError' => $this->lastError,
            'tenantId' => $this->tenant->getId(),
            'metadata' => $this->metadata,
            'connectionStatus' => $this->getConnectionStatus(),
        ];
    }

    public function __toString(): string
    {
        return sprintf('%s (%s)', $this->name, $this->identifier);
    }
}
