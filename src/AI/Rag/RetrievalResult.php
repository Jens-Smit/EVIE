<?php

namespace AppAIRag;

class RetrievalResult
{
    private array $items;

    public function __construct(
        private string $query,
        array $items = []
    ) {
        $this->items = $items;
    }

    public function getItems(): array
    {
        return $this->items;
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    public function getBestMatch(): ?RetrievedItem
    {
        return $this->items[0] ?? null;
    }

    public function getContextAsString(): string
    {
        $contexts = [];
        foreach ($this->items as $item) {
            $contexts[] = sprintf(
                "[Source: %s, Type: %s]
%s",
                $item->getSource() ?? 'unknown',
                $item->contentType,
                $item->getContent()
            );
        }
        return implode("

---

", $contexts);
    }

    public function getCount(): int
    {
        return count($this->items);
    }

    public function hasResults(): bool
    {
        return $this->getCount() > 0;
    }
}
