<?php

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Repository\EmbeddingRepository::class)]
#[ORM\Table(name: 'embeddings')]
class Embedding
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private ?string $contentHash = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $content = null;

    #[ORM\Column(type: Types::STRING, length: 100)]
    private ?string $contentType = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $source = null;

    #[ORM\Column(type: Types::JSON)]
    private array $metadata = [];

    #[ORM\Column(type: 'vector', name: 'vector', options: ['dimension' => 1024])]
    private array $vector = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getContentHash(): ?string
    {
        return $this->contentHash;
    }

    public function setContentHash(string $contentHash): static
    {
        $this->contentHash = $contentHash;
        return $this;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function setContent(string $content): static
    {
        $this->content = $content;
        $this->contentHash = hash('sha256', $content);
        return $this;
    }

    public function getContentType(): ?string
    {
        return $this->contentType;
    }

    public function setContentType(string $contentType): static
    {
        $this->contentType = $contentType;
        return $this;
    }

    public function getSource(): ?string
    {
        return $this->source;
    }

    public function setSource(?string $source): static
    {
        $this->source = $source;
        return $this;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function setMetadata(array $metadata): static
    {
        $this->metadata = $metadata;
        return $this;
    }

    public function getVector(): array
    {
        return $this->vector;
    }

    public function setVector(array $vector): static
    {
        $this->vector = $vector;
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

    public function cosineSimilarity(Embedding $other): float
    {
        $vectorA = $this->vector;
        $vectorB = $other->vector;
        
        $dotProduct = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        
        foreach ($vectorA as $i => $value) {
            $dotProduct += $value * ($vectorB[$i] ?? 0);
            $normA += $value * $value;
            $normB += ($vectorB[$i] ?? 0) * ($vectorB[$i] ?? 0);
        }
        
        if ($normA === 0 || $normB === 0) {
            return 0.0;
        }
        
        return $dotProduct / (sqrt($normA) * sqrt($normB));
    }
}