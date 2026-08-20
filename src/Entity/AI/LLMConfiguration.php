<?php

namespace App\Entity\AI;

use App\Entity\Tenant\User;
use App\Entity\Tenant\Organization;
use App\Entity\Tenant\Tenant;
use App\Repository\AI\LLMConfigurationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity(repositoryClass: LLMConfigurationRepository::class)]
#[ORM\Table(name: 'llm_configuration')]
#[ORM\Index(name: 'idx_llm_config_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_llm_config_organization', columns: ['organization_id'])]
#[ORM\Index(name: 'idx_llm_config_tenant', columns: ['tenant_id'])]
#[ORM\UniqueConstraint(name: 'uniq_llm_config_name_scope', columns: ['name', 'user_id', 'organization_id'])]
class LLMConfiguration
{
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 255, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uid_generator')]
    private ?string $id = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $name;

    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $provider;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $model;

    #[ORM\Column(type: Types::STRING, length: 500, nullable: true)]
    private ?string $endpoint = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $temperature = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $maxTokens = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $secretReference = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isDefault = false;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isFallback = false;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $priority = 0;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $configuration = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $metadata = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'llmConfigurations')]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: Organization::class, inversedBy: 'llmConfigurations')]
    #[ORM\JoinColumn(name: 'organization_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Organization $organization = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false)]
    private Tenant $tenant;

    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    private ?string $fallbackConfigurationId = null;

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

    public function getModel(): string
    {
        return $this->model;
    }

    public function setModel(string $model): static
    {
        $this->model = $model;
        return $this;
    }

    public function getEndpoint(): ?string
    {
        return $this->endpoint;
    }

    public function setEndpoint(?string $endpoint): static
    {
        $this->endpoint = $endpoint;
        return $this;
    }

    public function getTemperature(): ?float
    {
        return $this->temperature;
    }

    public function setTemperature(?float $temperature): static
    {
        $this->temperature = $temperature;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getMaxTokens(): ?int
    {
        return $this->maxTokens;
    }

    public function setMaxTokens(?int $maxTokens): static
    {
        $this->maxTokens = $maxTokens;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getSecretReference(): ?string
    {
        return $this->secretReference;
    }

    public function setSecretReference(?string $secretReference): static
    {
        $this->secretReference = $secretReference;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function setIsDefault(bool $isDefault): static
    {
        $this->isDefault = $isDefault;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function isFallback(): bool
    {
        return $this->isFallback;
    }

    public function setIsFallback(bool $isFallback): static
    {
        $this->isFallback = $isFallback;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function setPriority(int $priority): static
    {
        $this->priority = $priority;
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

    public function getConfiguration(): ?array
    {
        return $this->configuration;
    }

    public function setConfiguration(?array $configuration): static
    {
        $this->configuration = $configuration;
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

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;
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

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function setTenant(Tenant $tenant): static
    {
        $this->tenant = $tenant;
        return $this;
    }

    public function getFallbackConfigurationId(): ?string
    {
        return $this->fallbackConfigurationId;
    }

    public function setFallbackConfigurationId(?string $fallbackConfigurationId): static
    {
        $this->fallbackConfigurationId = $fallbackConfigurationId;
        return $this;
    }

    // --- Custom Methods ---

    /**
     * Get the scope of this configuration (user, organization, or tenant).
     */
    public function getScope(): string
    {
        if ($this->user !== null) {
            return 'user';
        }
        if ($this->organization !== null) {
            return 'organization';
        }
        return 'tenant';
    }

    /**
     * Get the scope ID (user ID, organization ID, or tenant ID).
     */
    public function getScopeId(): string
    {
        if ($this->user !== null) {
            return $this->user->getId();
        }
        if ($this->organization !== null) {
            return $this->organization->getId();
        }
        return $this->tenant->getId();
    }

    /**
     * Get the tenant ID for this configuration.
     */
    public function getTenantId(): string
    {
        return $this->tenant->getId();
    }

    /**
     * Check if this configuration belongs to the given tenant.
     */
    public function belongsToTenant(string $tenantId): bool
    {
        return $this->tenant->getId() === $tenantId;
    }

    /**
     * Check if this configuration belongs to the given user.
     */
    public function belongsToUser(string $userId): bool
    {
        if ($this->user === null) {
            return false;
        }
        return $this->user->getId() === $userId;
    }

    /**
     * Check if this configuration belongs to the given organization.
     */
    public function belongsToOrganization(string $organizationId): bool
    {
        if ($this->organization === null) {
            return false;
        }
        return $this->organization->getId() === $organizationId;
    }

    /**
     * Get the full identifier for this configuration.
     */
    public function getIdentifier(): string
    {
        $scope = $this->getScope();
        $scopeId = $this->getScopeId();
        return "{$scope}:{$scopeId}:{$this->name}";
    }

    /**
     * Convert to array for API responses.
     */
    public function toArray(bool $includeSecrets = false): array
    {
        $data = [
            'id' => $this->id,
            'name' => $this->name,
            'provider' => $this->provider,
            'model' => $this->model,
            'endpoint' => $this->endpoint,
            'temperature' => $this->temperature,
            'maxTokens' => $this->maxTokens,
            'secretReference' => $this->secretReference,
            'isDefault' => $this->isDefault,
            'isFallback' => $this->isFallback,
            'priority' => $this->priority,
            'scope' => $this->getScope(),
            'scopeId' => $this->getScopeId(),
            'createdAt' => $this->createdAt->format('c'),
            'updatedAt' => $this->updatedAt->format('c'),
            'configuration' => $this->configuration,
            'metadata' => $this->metadata,
        ];

        // Never include the actual secret value
        if ($includeSecrets && $this->secretReference) {
            $data['secretReference'] = '[REDACTED]';
        }

        return $data;
    }

    public function __toString(): string
    {
        return sprintf(
            '%s (%s): %s/%s',
            $this->name,
            $this->getScope(),
            $this->provider,
            $this->model
        );
    }
}
