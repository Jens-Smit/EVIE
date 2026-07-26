<?php

namespace App\AI\Skills\Tool;

use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

/**
 * Registry für alle EVIE-Tools.
 * Ermöglicht dynamische Registrierung und Abfrage von Tools.
 */
final class ToolRegistry
{
    /** @var iterable<ToolInterface> */
    private iterable $tools;

    /**
     * @param iterable<ToolInterface> $tools
     */
    public function __construct(
        #[TaggedIterator('ai.tool')]
        iterable $tools
    ) {
        $this->tools = $tools;
    }

    /**
     * Gibt ein Tool nach Namen zurück.
     *
     * @throws \InvalidArgumentException Falls das Tool nicht gefunden wird.
     */
    public function get(string $name): ToolInterface
    {
        foreach ($this->tools as $tool) {
            if ($tool->getName() === $name) {
                return $tool;
            }
        }
        throw new \InvalidArgumentException("Tool '$name' nicht gefunden.");
    }

    /**
     * Gibt alle registrierten Tools zurück.
     *
     * @return array<string, ToolInterface>
     */
    public function all(): array
    {
        $tools = [];
        foreach ($this->tools as $tool) {
            $tools[$tool->getName()] = $tool;
        }
        return $tools;
    }

    /**
     * Prüft, ob ein Tool mit dem gegebenen Namen existiert.
     */
    public function has(string $name): bool
    {
        foreach ($this->tools as $tool) {
            if ($tool->getName() === $name) {
                return true;
            }
        }
        return false;
    }
}