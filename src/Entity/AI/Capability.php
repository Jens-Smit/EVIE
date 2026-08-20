<?php

namespace App\Entity\AI;

use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\Organization;
use App\Repository\AI\CapabilityRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity(repositoryClass: CapabilityRepository::class)]
#[ORM\Table(name: 'capability')]
#[ORM\Index(name: 'idx_capability_name', columns: ['name'])]
#[ORM\Index(name: 'idx_capability_identifier', columns: ['identifier'])]
#[ORM\Index(name: 'idx_capability_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_capability_enabled', columns: ['is_enabled'])]
#[ORM\UniqueConstraint(name: 'uniq_capability_identifier_tenant', columns: ['tenant_id', 'identifier'])]
class Capability
{
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 255, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uid_generator')]
    private ?string $id = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $name;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $identifier;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $category = 'general';

    #[ORM\Column(type: Types::JSON)]
    private array $configuration = [];

    #[ORM\Column(type: Types::JSON)]
    private array $requiredSecrets = [];

    #[ORM\Column(type: Types::JSON)]
    private array $requiredIntegrations = [];

    #[ORM\Column(type: Types::JSON)]
    private array $requiredPermissions = [];

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $parameters = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isEnabled = true;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isInstalled = false;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isConfigured = false;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false)]
    private Tenant $tenant;

    #[ORM\ManyToOne(targetEntity: Organization::class, inversedBy: 'capabilities')]
    #[ORM\JoinColumn(name: 'organization_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Organization $organization = null;

    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    private ?string $provider = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
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

    public function getCategory(): string
    {
        return $this->category;
    }

    public function setCategory(string $category): static
    {
        $this->category = $category;
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

    public function getRequiredSecrets(): array
    {
        return $this->requiredSecrets;
    }

    public function setRequiredSecrets(array $requiredSecrets): static
    {
        $this->requiredSecrets = $requiredSecrets;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function addRequiredSecret(string $secretKey, array $secretConfig = []): static
    {
        $this->requiredSecrets[$secretKey] = array_merge(
            [
                'description' => '',
                'required' => true,
                'type' => 'string',
            ],
            $secretConfig
        );
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function removeRequiredSecret(string $secretKey): static
    {
        unset($this->requiredSecrets[$secretKey]);
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getRequiredIntegrations(): array
    {
        return $this->requiredIntegrations;
    }

    public function setRequiredIntegrations(array $requiredIntegrations): static
    {
        $this->requiredIntegrations = $requiredIntegrations;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function addRequiredIntegration(string $integration, array $config = []): static
    {
        $this->requiredIntegrations[$integration] = array_merge(
            [
                'description' => '',
                'required' => true,
            ],
            $config
        );
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function removeRequiredIntegration(string $integration): static
    {
        unset($this->requiredIntegrations[$integration]);
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getRequiredPermissions(): array
    {
        return $this->requiredPermissions;
    }

    public function setRequiredPermissions(array $requiredPermissions): static
    {
        $this->requiredPermissions = $requiredPermissions;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function addRequiredPermission(string $permission, array $config = []): static
    {
        $this->requiredPermissions[$permission] = array_merge(
            [
                'description' => '',
                'required' => true,
            ],
            $config
        );
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function removeRequiredPermission(string $permission): static
    {
        unset($this->requiredPermissions[$permission]);
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getParameters(): ?array
    {
        return $this->parameters;
    }

    public function setParameters(?array $parameters): static
    {
        $this->parameters = $parameters;
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

    public function isInstalled(): bool
    {
        return $this->isInstalled;
    }

    public function setIsInstalled(bool $isInstalled): static
    {
        $this->isInstalled = $isInstalled;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function markAsInstalled(): static
    {
        $this->isInstalled = true;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function markAsUninstalled(): static
    {
        $this->isInstalled = false;
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

    public function getProvider(): ?string
    {
        return $this->provider;
    }

    public function setProvider(?string $provider): static
    {
        $this->provider = $provider;
        $this->updatedAt = new \DateTimeImmutable();
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
     * Get the tenant ID for this capability.
     */
    public function getTenantId(): string
    {
        return $this->tenant->getId();
    }

    /**
     * Get the organization ID for this capability.
     */
    public function getOrganizationId(): ?string
    {
        return $this->organization?->getId();
    }

    /**
     * Check if this capability belongs to the given tenant.
     */
    public function belongsToTenant(string $tenantId): bool
    {
        return $this->tenant->getId() === $tenantId;
    }

    /**
     * Check if this capability belongs to the given organization.
     */
    public function belongsToOrganization(string $organizationId): bool
    {
        if ($this->organization === null) {
            return false;
        }
        return $this->organization->getId() === $organizationId;
    }

    /**
     * Check if this capability requires a specific secret.
     */
    public function requiresSecret(string $secretKey): bool
    {
        return isset($this->requiredSecrets[$secretKey]);
    }

    /**
     * Check if this capability requires a specific integration.
     */
    public function requiresIntegration(string $integration): bool
    {
        return isset($this->requiredIntegrations[$integration]);
    }

    /**
     * Check if this capability requires a specific permission.
     */
    public function requiresPermission(string $permission): bool
    {
        return isset($this->requiredPermissions[$permission]);
    }

    /**
     * Check if this capability is ready to be used.
     */
    public function isReady(): bool
    {
        return $this->isEnabled && $this->isInstalled && $this->isConfigured;
    }

    /**
     * Get the requirements for this capability.
     */
    public function getRequirements(): array
    {
        return [
            'secrets' => array_keys($this->requiredSecrets),
            'integrations' => array_keys($this->requiredIntegrations),
            'permissions' => array_keys($this->requiredPermissions),
        ];
    }

    /**
     * Check if all required secrets are configured.
     */
    public function hasRequiredSecrets(array $availableSecrets): bool
    {
        foreach ($this->requiredSecrets as $secretKey => $config) {
            if ($config['required'] && !isset($availableSecrets[$secretKey])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Check if all required integrations are available.
     */
    public function hasRequiredIntegrations(array $availableIntegrations): bool
    {
        foreach ($this->requiredIntegrations as $integration => $config) {
            if ($config['required'] && !in_array($integration, $availableIntegrations, true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Check if all required permissions are granted.
     */
    public function hasRequiredPermissions(array $availablePermissions): bool
    {
        foreach ($this->requiredPermissions as $permission => $config) {
            if ($config['required'] && !in_array($permission, $availablePermissions, true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Convert to array for API responses.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'identifier' => $this->identifier,
            'description' => $this->description,
            'category' => $this->category,
            'configuration' => $this->configuration,
            'requiredSecrets' => $this->requiredSecrets,
            'requiredIntegrations' => $this->requiredIntegrations,
            'requiredPermissions' => $this->requiredPermissions,
            'parameters' => $this->parameters,
            'isEnabled' => $this->isEnabled,
            'isInstalled' => $this->isInstalled,
            'isConfigured' => $this->isConfigured,
            'createdAt' => $this->createdAt->format('c'),
            'updatedAt' => $this->updatedAt->format('c'),
            'tenantId' => $this->tenant->getId(),
            'organizationId' => $this->organization?->getId(),
            'provider' => $this->provider,
            'version' => $this->version,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Get a short description of the capability.
     */
    public function getShortDescription(): string
    {
        return sprintf(
            '%s (%s) - %s',
            $this->name,
            $this->identifier,
            $this->description ?? 'No description'
        );
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
