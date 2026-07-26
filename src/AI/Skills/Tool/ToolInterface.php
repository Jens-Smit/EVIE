<?php

namespace App\AI\Skills\Tool;

/**
 * Interface für alle EVIE-Tools.
 * Jedes Tool muss diese Methode implementieren, um vom Agenten aufgerufen werden zu können.
 */
interface ToolInterface
{
    /**
     * Führt das Tool mit den gegebenen Parametern aus.
     */
    public function __invoke(array $parameters = []): array;

    /**
     * Gibt den Namen des Tools zurück.
     */
    public function getName(): string;

    /**
     * Gibt eine Beschreibung des Tools zurück.
     */
    public function getDescription(): string;
}