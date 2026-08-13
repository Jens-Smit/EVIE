<?php

namespace AppAIRag;

use AppEntityEmbedding;

class RetrievedItem
{
    public function __construct(
        public Embedding $embedding,
        public float $similarity,
        public string $contentType
    ) {
    }

    public function getContent(): string
    {
        return $this->embedding->getContent();
    }

    public function getMetadata(): array
    {
        return $this->embedding->getMetadata();
    }

    public function getSource(): ?string
    {
        return $this->embedding->getSource();
    }
}
