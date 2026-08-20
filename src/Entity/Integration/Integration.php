<?php

namespace App\Entity\Integration;

use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\Organization;
use App\Repository\Integration\IntegrationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity(repositoryClass: IntegrationRepository::class)]
#[ORM\Table(name: 'integration')]
#[ORM\Index(name: 'idx_integration_identifier', columns: ['identifier'])]
#[ORM\Index(name: 'idx_integration_type', columns: ['type'])]
#[ORM\Index(name: 'idx_integration_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_integration_enabled', columns: ['is_enabled'])]
#[ORM\Index(name: 'idx_integration_connected', columns: ['is_connected'])]
#[ORM\UniqueConstraint(name: 'uniq_integration_type_tenant', columns: ['tenant_id', 'type', 'identifier'])]
class Integration
{
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 255, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uid_generator')]
    private ?string $id = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $identifier;

    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $type;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::STRING, length: 2048, nullable: true)]
    private ?string $baseUrl = null;

    #[ORM\Column(type: Types::JSON)]
    private array $configuration = [];

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $credentials = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $scopes = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $capabilities = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isEnabled = true;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isConnected = false;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isConfigured = false;

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

    #[ORM\ManyToOne(targetEntity: Organization::class, inversedBy: 'integrations')]
    #[ORM\JoinColumn(name: 'organization_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Organization $organization = null;

    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    private ?string $version = null;

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

    public function getBaseUrl(): ?string
    {
        return $this->baseUrl;
    }

    public function setBaseUrl(?string $baseUrl): static
    {
        $this->baseUrl = $baseUrl;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getConfiguration(): array
    {
        return $this->configuration;
    }

    public function setConfiguration(array $configuration): static
    {
        $this->configuration = $configuration;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getCredentials(): ?array
    {
        return $this->credentials;
    }

    public function setCredentials(?array $credentials): static
    {
        $this->credentials = $credentials;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getScopes(): ?array
    {
        return $this->scopes;
    }

    public function setScopes(?array $scopes): static
    {
        $this->scopes = $scopes;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function addScope(string $scope): static
    {
        if ($this->scopes === null) {
            $this->scopes = [];
        }
        if (!in_array($scope, $this->scopes, true)) {
            $this->scopes[] = $scope;
        }
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function removeScope(string $scope): static
    {
        if ($this->scopes !== null) {
            $index = array_search($scope, $this->scopes, true);
            if ($index !== false) {
                unset($this->scopes[$index]);
                $this->scopes = array_values($this->scopes);
            }
        }
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function hasScope(string $scope): bool
    {
        return $this->scopes !== null && in_array($scope, $this->scopes, true);
    }

    public function getCapabilities(): ?array
    {
        return $this->capabilities;
    }

    public function setCapabilities(?array $capabilities): static
    {
        $this->capabilities = $capabilities;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function addCapability(string $capability): static
    {
        if ($this->capabilities === null) {
            $this->capabilities = [];
        }
        if (!in_array($capability, $this->capabilities, true)) {
            $this->capabilities[] = $capability;
        }
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function removeCapability(string $capability): static
    {
        if ($this->capabilities !== null) {
            $index = array_search($capability, $this->capabilities, true);
            if ($index !== false) {
                unset($this->capabilities[$index]);
                $this->capabilities = array_values($this->capabilities);
            }
        }
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function hasCapability(string $capability): bool
    {
        return $this->capabilities !== null && in_array($capability, $this->capabilities, true);
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

    public function isConfigured(): bool
    {
        return $this->isConfigured;
    }

    public function setIsConfigured(bool $isConfigured): static
    {
        $this->isConfigured = $isConfigured;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function markAsConfigured(): static
    {
        $this->isConfigured = true;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function markAsUnconfigured(): static
    {
        $this->isConfigured = false;
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

    public function getOrganization(): ?Organization
    {
        return $this->organization;
    }

    public function setOrganization(?Organization $organization): static
    {
        $this->organization = $organization;
        return $this;
    }

    public function getVersion(): ?string
    {
        return $this->version;
    }

    public function setVersion(?string $version): static
    {
        $this->version = $version;
        $this->updatedAt = new \DateTimeImmutable();
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
     * Get the tenant ID for this integration.
     */
    public function getTenantId(): string
    {
        return $this->tenant->getId();
    }

    /**
     * Get the organization ID for this integration.
     */
    public function getOrganizationId(): ?string
    {
        return $this->organization?->getId();
    }

    /**
     * Check if this integration belongs to the given tenant.
     */
    public function belongsToTenant(string $tenantId): bool
    {
        return $this->tenant->getId() === $tenantId;
    }

    /**
     * Check if this integration belongs to the given organization.
     */
    public function belongsToOrganization(string $organizationId): bool
    {
        if ($this->organization === null) {
            return false;
        }
        return $this->organization->getId() === $organizationId;
    }

    /**
     * Check if this integration is ready to use.
     */
    public function isReady(): bool
    {
        return $this->isEnabled && $this->isConfigured && $this->isConnected;
    }

    /**
     * Get the connection status.
     */
    public function getConnectionStatus(): string
    {
        if (!$this->isEnabled) {
            return 'disabled';
        }

        if (!$this->isConfigured) {
            return 'unconfigured';
        }

        if (!$this->isConnected) {
            return 'disconnected';
        }

        return 'connected';
    }

    /**
     * Get credential by key.
     */
    public function getCredential(string $key): mixed
    {
        if ($this->credentials === null) {
            return null;
        }

        return $this->credentials[$key] ?? null;
    }

    /**
     * Set credential by key.
     */
    public function setCredential(string $key, mixed $value): static
    {
        if ($this->credentials === null) {
            $this->credentials = [];
        }

        $this->credentials[$key] = $value;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    /**
     * Remove credential by key.
     */
    public function removeCredential(string $key): static
    {
        if ($this->credentials !== null) {
            unset($this->credentials[$key]);
        }
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    /**
     * Get configuration by key.
     */
    public function getConfig(string $key): mixed
    {
        return $this->configuration[$key] ?? null;
    }

    /**
     * Set configuration by key.
     */
    public function setConfig(string $key, mixed $value): static
    {
        $this->configuration[$key] = $value;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    /**
     * Convert to array for API responses.
     */
    public function toArray(bool $includeCredentials = false): array
    {
        $data = [
            'id' => $this->id,
            'identifier' => $this->identifier,
            'type' => $this->type,
            'name' => $this->name,
            'description' => $this->description,
            'baseUrl' => $this->baseUrl,
            'configuration' => $this->configuration,
            'scopes' => $this->scopes,
            'capabilities' => $this->capabilities,
            'isEnabled' => $this->isEnabled,
            'isConnected' => $this->isConnected,
            'isConfigured' => $this->isConfigured,
            'createdAt' => $this->createdAt->format('c'),
            'updatedAt' => $this->updatedAt->format('c'),
            'lastConnectedAt' => $this->lastConnectedAt?->format('c'),
            'lastErrorAt' => $this->lastErrorAt?->format('c'),
            'lastError' => $this->lastError,
            'tenantId' => $this->tenant->getId(),
            'organizationId' => $this->organization?->getId(),
            'version' => $this->version,
            'metadata' => $this->metadata,
            'connectionStatus' => $this->getConnectionStatus(),
        ];

        if ($includeCredentials) {
            $data['credentials'] = $this->credentials;
        }

        return $data;
    }

    public function __toString(): string
    {
        return sprintf('%s (%s)', $this->name, $this->type);
    }
}
