<?php

declare(strict_types=1);

namespace App\Tests\Stub;

use App\AI\Skills\Tool\ToolInterface;

/**
 * In-Memory-Tool fuer Unit-Tests: liefert die gesetzten Parameter als
 * Ergebnis zurueck, damit Tests die Parameter-Uebergabe verifizieren
 * koennen, ohne echte Tools zu instanziieren.
 */
final class InMemoryTool implements ToolInterface
{
    /** @param array<string, mixed> $parameters */
    public function __construct(
        private readonly string $name,
        private readonly string $description,
        array $parameters = []
    ) {
        unset($parameters);
    }

    public function __invoke(array $parameters = []): array
    {
        return $parameters;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }
}
