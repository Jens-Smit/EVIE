<?php

namespace App\Entity;

use App\Repository\ToolCategoryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ToolCategoryRepository::class)]
class ToolCategory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\OneToMany(targetEntity: ToolDefinition::class, mappedBy: 'category')]
    private Collection $tools;

    public function __construct()
    {
        $this->tools = new ArrayCollection();
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    /**
     * @return Collection<int, ToolDefinition>
     */
    public function getTools(): Collection
    {
        return $this->tools;
    }

    public function addTool(ToolDefinition $tool): static
    {
        if (!$this->tools->contains($tool)) {
            $this->tools->add($tool);
            $tool->setCategory($this);
        }
        return $this;
    }

    public function removeTool(ToolDefinition $tool): static
    {
        if ($this->tools->removeElement($tool)) {
            if ($tool->getCategory() === $this) {
                $tool->setCategory(null);
            }
        }
        return $this;
    }
}
