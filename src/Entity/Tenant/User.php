<?php

namespace App\Entity\Tenant;

use App\Entity\AI\Conversation;
use App\Entity\AI\LLMConfiguration;
use App\Entity\Automation\ScheduledTask;
use App\Entity\Security\UserSecret;
use App\Repository\Tenant\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ORM\Index(name: 'idx_user_email', columns: ['email'])]
#[ORM\Index(name: 'idx_user_tenant', columns: ['tenant_id'])]
#[ORM\UniqueConstraint(name: 'uniq_user_email_tenant', columns: ['tenant_id', 'email'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 255, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uid_generator')]
    private ?string $id = null;

    #[ORM\Column(type: Types::STRING, length: 180, unique: true)]
    private string $email;

    #[ORM\Column(type: Types::JSON)]
    private array $roles = [];

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $password = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $firstName = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $lastName = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\ManyToOne(targetEntity: Tenant::class, inversedBy: 'users')]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false)]
    private Tenant $tenant;

    #[ORM\OneToMany(mappedBy: 'user', targetEntity: UserSecret::class, orphanRemoval: true, cascade: ['persist'])]
    private Collection $secrets;

    #[ORM\OneToMany(mappedBy: 'user', targetEntity: LLMConfiguration::class, orphanRemoval: true)]
    private Collection $llmConfigurations;

    #[ORM\OneToMany(mappedBy: 'user', targetEntity: Conversation::class, orphanRemoval: true)]
    private Collection $conversations;

    #[ORM\OneToMany(mappedBy: 'user', targetEntity: ScheduledTask::class, orphanRemoval: true)]
    private Collection $scheduledTasks;

    #[ORM\ManyToOne(targetEntity: Organization::class, inversedBy: 'users')]
    #[ORM\JoinColumn(name: 'organization_id', referencedColumnName: 'id')]
    private ?Organization $organization = null;

    public function __construct()
    {
        $this->id = Ulid::generate();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->secrets = new ArrayCollection();
        $this->llmConfigurations = new ArrayCollection();
        $this->conversations = new ArrayCollection();
        $this->scheduledTasks = new ArrayCollection();
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

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;
        return $this;
    }

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /**
     * @see UserInterface
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        // guarantee every user at least has ROLE_USER
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    public function setRoles(array $roles): static
    {
        $this->roles = $roles;
        return $this;
    }

    public function addRole(string $role): static
    {
        if (!in_array($role, $this->roles, true)) {
            $this->roles[] = $role;
        }
        return $this;
    }

    public function removeRole(string $role): static
    {
        $index = array_search($role, $this->roles, true);
        if ($index !== false) {
            unset($this->roles[$index]);
            $this->roles = array_values($this->roles);
        }
        return $this;
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->getRoles(), true);
    }

    /**
     * @see PasswordAuthenticatedUserInterface
     */
    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(?string $password): static
    {
        $this->password = $password;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function setFirstName(?string $firstName): static
    {
        $this->firstName = $firstName;
        return $this;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function setLastName(?string $lastName): static
    {
        $this->lastName = $lastName;
        return $this;
    }

    public function getFullName(): string
    {
        return trim(sprintf('%s %s', $this->firstName ?? '', $this->lastName ?? ''));
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
     * @return Collection<int, UserSecret>
     */
    public function getSecrets(): Collection
    {
        return $this->secrets;
    }

    public function addSecret(UserSecret $secret): static
    {
        if (!$this->secrets->contains($secret)) {
            $this->secrets->add($secret);
            $secret->setUser($this);
        }

        return $this;
    }

    public function removeSecret(UserSecret $secret): static
    {
        if ($this->secrets->removeElement($secret)) {
            // set the owning side to null (unless already changed)
            if ($secret->getUser() === $this) {
                $secret->setUser(null);
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
            $llmConfiguration->setUser($this);
        }

        return $this;
    }

    public function removeLlmConfiguration(LLMConfiguration $llmConfiguration): static
    {
        if ($this->llmConfigurations->removeElement($llmConfiguration)) {
            // set the owning side to null (unless already changed)
            if ($llmConfiguration->getUser() === $this) {
                $llmConfiguration->setUser(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Conversation>
     */
    public function getConversations(): Collection
    {
        return $this->conversations;
    }

    public function addConversation(Conversation $conversation): static
    {
        if (!$this->conversations->contains($conversation)) {
            $this->conversations->add($conversation);
            $conversation->setUser($this);
        }

        return $this;
    }

    public function removeConversation(Conversation $conversation): static
    {
        if ($this->conversations->removeElement($conversation)) {
            // set the owning side to null (unless already changed)
            if ($conversation->getUser() === $this) {
                $conversation->setUser(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, ScheduledTask>
     */
    public function getScheduledTasks(): Collection
    {
        return $this->scheduledTasks;
    }

    public function addScheduledTask(ScheduledTask $scheduledTask): static
    {
        if (!$this->scheduledTasks->contains($scheduledTask)) {
            $this->scheduledTasks->add($scheduledTask);
            $scheduledTask->setUser($this);
        }

        return $this;
    }

    public function removeScheduledTask(ScheduledTask $scheduledTask): static
    {
        if ($this->scheduledTasks->removeElement($scheduledTask)) {
            // set the owning side to null (unless already changed)
            if ($scheduledTask->getUser() === $this) {
                $scheduledTask->setUser(null);
            }
        }

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

    // --- Custom Methods ---

    public function __toString(): string
    {
        return $this->email;
    }

    /**
     * Check if this user belongs to the given tenant.
     */
    public function belongsToTenant(string $tenantId): bool
    {
        return $this->tenant->getId() === $tenantId;
    }

    /**
     * Check if this user belongs to the given organization.
     */
    public function belongsToOrganization(string $organizationId): bool
    {
        if ($this->organization === null) {
            return false;
        }
        return $this->organization->getId() === $organizationId;
    }

    /**
     * Get the default LLM configuration for this user.
     */
    public function getDefaultLlmConfiguration(): ?LLMConfiguration
    {
        foreach ($this->llmConfigurations as $config) {
            if ($config->isDefault()) {
                return $config;
            }
        }
        return null;
    }

    /**
     * Get the last active conversation for this user.
     */
    public function getLastConversation(): ?Conversation
    {
        if ($this->conversations->isEmpty()) {
            return null;
        }

        $conversations = $this->conversations->toArray();
        usort($conversations, function(Conversation $a, Conversation $b) {
            return $b->getUpdatedAt() <=> $a->getUpdatedAt();
        });

        foreach ($conversations as $conversation) {
            if ($conversation->isActive()) {
                return $conversation;
            }
        }

        return $conversations[0] ?? null;
    }

    /**
     * Get active scheduled tasks for this user.
     *
     * @return ScheduledTask[]
     */
    public function getActiveScheduledTasks(): array
    {
        return array_filter(
            $this->scheduledTasks->toArray(),
            function(ScheduledTask $task) {
                return $task->isActive();
            }
        );
    }

    /**
     * Get due scheduled tasks for this user.
     *
     * @return ScheduledTask[]
     */
    public function getDueScheduledTasks(): array
    {
        return array_filter(
            $this->scheduledTasks->toArray(),
            function(ScheduledTask $task) {
                return $task->isActive() && $task->isDue();
            }
        );
    }

    /**
     * @see UserInterface
     */
    public function eraseCredentials(): void
    {
        // If you store any temporary, sensitive data on the user, clear it here
        // $this->plainPassword = null;
    }
}