<?php

namespace App\Entity\Security;

use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\User;
use App\Repository\Security\PolicyRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity(repositoryClass: PolicyRepository::class)]
#[ORM\Table(name: 'policy')]
#[ORM\Index(name: 'idx_policy_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_policy_identifier', columns: ['identifier'])]
#[ORM\Index(name: 'idx_policy_type', columns: ['policy_type'])]
#[ORM\Index(name: 'idx_policy_enabled', columns: ['is_enabled'])]
#[ORM\UniqueConstraint(name: 'uniq_policy_identifier_tenant', columns: ['tenant_id', 'identifier'])]
class Policy
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

    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $policyType = 'action';

    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $effect = 'ask';

    #[ORM\Column(type: Types::JSON)]
    private array $conditions = [];

    #[ORM\Column(type: Types::JSON)]
    private array $actions = [];

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $resources = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $exceptions = null;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $priority = 0;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isEnabled = true;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false)]
    private Tenant $tenant;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'policies')]
    #[ORM\JoinColumn(name: 'created_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

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

    public function getPolicyType(): string
    {
        return $this->policyType;
    }

    public function setPolicyType(string $policyType): static
    {
        $this->policyType = $policyType;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getEffect(): string
    {
        return $this->effect;
    }

    public function setEffect(string $effect): static
    {
        $this->effect = $effect;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getConditions(): array
    {
        return $this->conditions;
    }

    public function setConditions(array $conditions): static
    {
        $this->conditions = $conditions;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function addCondition(string $key, mixed $value): static
    {
        $this->conditions[$key] = $value;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function removeCondition(string $key): static
    {
        unset($this->conditions[$key]);
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getActions(): array
    {
        return $this->actions;
    }

    public function setActions(array $actions): static
    {
        $this->actions = $actions;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function addAction(string $action): static
    {
        $this->actions[] = $action;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function removeAction(string $action): static
    {
        $index = array_search($action, $this->actions, true);
        if ($index !== false) {
            unset($this->actions[$index]);
            $this->actions = array_values($this->actions);
        }
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

    public function addResource(string $resource): static
    {
        $this->resources[] = $resource;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function removeResource(string $resource): static
    {
        if ($this->resources !== null) {
            $index = array_search($resource, $this->resources, true);
            if ($index !== false) {
                unset($this->resources[$index]);
                $this->resources = array_values($this->resources);
            }
        }
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getExceptions(): ?array
    {
        return $this->exceptions;
    }

    public function setExceptions(?array $exceptions): static
    {
        $this->exceptions = $exceptions;
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

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;
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
     * Get the tenant ID for this policy.
     */
    public function getTenantId(): string
    {
        return $this->tenant->getId();
    }

    /**
     * Check if this policy belongs to the given tenant.
     */
    public function belongsToTenant(string $tenantId): bool
    {
        return $this->tenant->getId() === $tenantId;
    }

    /**
     * Check if this policy applies to the given action.
     */
    public function appliesToAction(string $action): bool
    {
        return in_array($action, $this->actions, true) || 
               in_array('*', $this->actions, true);
    }

    /**
     * Check if this policy applies to the given resource.
     */
    public function appliesToResource(string $resource): bool
    {
        if ($this->resources === null) {
            return true; // No resource restrictions
        }

        return in_array($resource, $this->resources, true) || 
               in_array('*', $this->resources, true);
    }

    /**
     * Check if the policy conditions are met.
     */
    public function conditionsMet(array $context): bool
    {
        if (empty($this->conditions)) {
            return true; // No conditions means always apply
        }

        foreach ($this->conditions as $key => $expectedValue) {
            if (!isset($context[$key])) {
                return false;
            }

            if ($context[$key] != $expectedValue) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if an exception applies.
     */
    public function hasException(array $context): bool
    {
        if ($this->exceptions === null) {
            return false;
        }

        foreach ($this->exceptions as $exception) {
            $exceptionConditions = $exception['conditions'] ?? [];
            $allMet = true;

            foreach ($exceptionConditions as $key => $expectedValue) {
                if (!isset($context[$key]) || $context[$key] != $expectedValue) {
                    $allMet = false;
                    break;
                }
            }

            if ($allMet) {
                return true;
            }
        }

        return false;
    }

    /**
     * Evaluate the policy for a given context.
     */
    public function evaluate(array $context): string
    {
        // Check if policy is enabled
        if (!$this->isEnabled) {
            return 'allow';
        }

        // Check if policy applies to action
        $action = $context['action'] ?? '';
        if (!$this->appliesToAction($action)) {
            return 'allow';
        }

        // Check if policy applies to resource
        $resource = $context['resource'] ?? '';
        if (!$this->appliesToResource($resource)) {
            return 'allow';
        }

        // Check if exception applies
        if ($this->hasException($context)) {
            return 'allow';
        }

        // Check if conditions are met
        if (!$this->conditionsMet($context)) {
            return 'allow';
        }

        // Return the policy effect
        return $this->effect;
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
            'policyType' => $this->policyType,
            'effect' => $this->effect,
            'conditions' => $this->conditions,
            'actions' => $this->actions,
            'resources' => $this->resources,
            'exceptions' => $this->exceptions,
            'priority' => $this->priority,
            'isEnabled' => $this->isEnabled,
            'createdAt' => $this->createdAt->format('c'),
            'updatedAt' => $this->updatedAt->format('c'),
            'tenantId' => $this->tenant->getId(),
            'createdById' => $this->createdBy?->getId(),
            'metadata' => $this->metadata,
        ];
    }

    public function __toString(): string
    {
        return sprintf('%s: %s', $this->identifier, $this->effect);
    }
}
