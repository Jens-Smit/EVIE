<?php

namespace App\AI\Rag;

interface EmbeddingServiceInterface
{
    public function embedText(string $text): array;
    public function embedTextBatch(array $texts): array;
    public function getDimension(): int;
    public function getModelName(): string;
}