<?php

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Repository\OrganizationRepository::class)]
#[ORM\Table(name: 'organizations')]
class Organization
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 255, unique: true)]
    private ?string $slug = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $updatedAt = null;

    #[ORM\OneToMany(mappedBy: 'organization', targetEntity: User::class)]
    private Collection $users;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $settings = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $rbacConfig = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
        $this->users = new ArrayCollection();
        $this->settings = [];
        $this->rbacConfig = [];
    }

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

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;
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

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;
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
            $user->setOrganizationId($this->getId());
        }
        return $this;
    }

    public function removeUser(User $user): static
    {
        if ($this->users->removeElement($user)) {
            if ($user->getOrganizationId() === $this->getId()) {
                $user->setOrganizationId(null);
            }
        }
        return $this;
    }

    public function getSettings(): ?array
    {
        return $this->settings;
    }

    public function setSettings(?array $settings): static
    {
        $this->settings = $settings;
        return $this;
    }

    public function getRbacConfig(): ?array
    {
        return $this->rbacConfig;
    }

    public function setRbacConfig(?array $rbacConfig): static
    {
        $this->rbacConfig = $rbacConfig;
        return $this;
    }

    /**
     * Prft, ob der Benutzer eine bestimmte Rolle in dieser Organisation hat.
     */
    public function hasUserWithRole(string $userIdentifier, string $role): bool
    {
        foreach ($this->users as $user) {
            if ($user->getUserIdentifier() === $userIdentifier && $user->hasRole($role)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Prft, ob der Benutzer eine bestimmte Berechtigung hat.
     */
    public function hasUserPermission(string $userIdentifier, string $permission): bool
    {
        $rbac = $this->rbacConfig ?? [];
        
        if (!isset($rbac['roles'])) {
            return false;
        }
        
        foreach ($this->users as $user) {
            if ($user->getUserIdentifier() !== $userIdentifier) {
                continue;
            }
            
            foreach ($user->getRoles() as $role) {
                if (isset($rbac['roles'][$role]['permissions']) && 
                    in_array($permission, $rbac['roles'][$role]['permissions'])) {
                    return true;
                }
            }
        }
        
        return false;
    }
}
