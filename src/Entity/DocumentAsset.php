<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DocumentAssetRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Bild-/Screenshot-Asset eines Dokuments (Luecke 2 Fixplan Rev. 2).
 *
 * Ein Document kann beliebig viele Assets haben (z.B. Screenshots aus
 * der Playwright-MCP-Integration). Jedes Asset verweist auf eine
 * gespeicherte Datei, traegt einen Alternativtext (aus der
 * Vision-Beschreibung), einen Abschnittsbezug und eine Position fuer
 * die Montage im Markdown-Dokument.
 */
#[ORM\Entity(repositoryClass: DocumentAssetRepository::class)]
#[ORM\Table(name: 'document_asset')]
class DocumentAsset
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Document::class, inversedBy: 'assets')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Document $document = null;

    #[ORM\Column(length: 255)]
    private string $filePath;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $altText = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $sectionRef = null;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDocument(): ?Document
    {
        return $this->document;
    }

    public function setDocument(?Document $document): static
    {
        $this->document = $document;

        return $this;
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }

    public function setFilePath(string $filePath): static
    {
        $this->filePath = $filePath;

        return $this;
    }

    public function getAltText(): ?string
    {
        return $this->altText;
    }

    public function setAltText(?string $altText): static
    {
        $this->altText = $altText;

        return $this;
    }

    public function getSectionRef(): ?string
    {
        return $this->sectionRef;
    }

    public function setSectionRef(?string $sectionRef): static
    {
        $this->sectionRef = $sectionRef;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

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
}
