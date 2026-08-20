<?php

namespace App\Entity\Tenant;

use App\Entity\AI\LLMConfiguration;
use App\Repository\Tenant\OrganizationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity(repositoryClass: OrganizationRepository::class)]
#[ORM\Table(name: 'organization')]
#[ORM\Index(name: 'idx_organization_name', columns: ['name'])]
#[ORM\Index(name: 'idx_organization_tenant', columns: ['tenant_id'])]
class Organization
{
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 255, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uid_generator')]
    private ?string $id = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $name;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $slug = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $configuration = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\ManyToOne(targetEntity: Tenant::class, inversedBy: 'organizations')]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false)]
    private Tenant $tenant;

    #[ORM\OneToMany(mappedBy: 'organization', targetEntity: User::class)]
    private Collection $users;

    #[ORM\OneToMany(mappedBy: 'organization', targetEntity: LLMConfiguration::class, orphanRemoval: true)]
    private Collection $llmConfigurations;

    public function __construct()
    {
        $this->id = Ulid::generate();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->users = new ArrayCollection();
        $this->llmConfigurations = new ArrayCollection();
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

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(?string $slug): static
    {
        $this->slug = $slug;
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

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;
        $this->updatedAt = new \DateTimeImmutable();
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

    public function getTenantId(): string
    {
        return $this->tenant->getId();
    }

    /**
     * @return Collection<int, User>
     */
    public function getUsers(): Collection
    {
        return $this->users;
    }

    public function addUser(User $user): static
    {
        if (!$this->users->contains($user)) {
            $this->users->add($user);
            $user->setOrganization($this);
        }

        return $this;
    }

    public function removeUser(User $user): static
    {
        if ($this->users->removeElement($user)) {
            // set the owning side to null (unless already changed)
            if ($user->getOrganization() === $this) {
                $user->setOrganization(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, LLMConfiguration>
     */
    public function getLlmConfigurations(): Collection
    {
        return $this->llmConfigurations;
    }

    public function addLlmConfiguration(LLMConfiguration $llmConfiguration): static
    {
        if (!$this->llmConfigurations->contains($llmConfiguration)) {
            $this->llmConfigurations->add($llmConfiguration);
            $llmConfiguration->setOrganization($this);
        }

        return $this;
    }

    public function removeLlmConfiguration(LLMConfiguration $llmConfiguration): static
    {
        if ($this->llmConfigurations->removeElement($llmConfiguration)) {
            // set the owning side to null (unless already changed)
            if ($llmConfiguration->getOrganization() === $this) {
                $llmConfiguration->setOrganization(null);
            }
        }

        return $this;
    }

    // --- Custom Methods ---

    public function __toString(): string
    {
        return $this->name;
    }

    /**
     * Check if this organization belongs to the given tenant.
     */
    public function belongsToTenant(string $tenantId): bool
    {
        return $this->tenant->getId() === $tenantId;
    }
}