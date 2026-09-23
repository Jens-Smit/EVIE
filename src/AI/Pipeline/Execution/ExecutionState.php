<?php

declare(strict_types=1);

namespace App\AI\Pipeline\Execution;

/**
 * Value-Object: Ergebnis-Store eines Workflow-Laufs (Phase 5).
 *
 * Der ExecutionCoordinator legt pro ausgefuehrtem Schritt das Ergebnis
 * unter dem output_key des Schritts ab. Nachfolgende Schritte lesen die
 * Ergebnisse ihrer input_from-Steps und erhalten damit die Ausgaben
 * vorheriger Schritte (z.B. Research-Ergebnis -> Analyse) als Input.
 *
 * Rein speicherseitig pro Lauf; keine Persistenz.
 *
 * @see docs/architecture/orchestrator-pipeline.md Phase 5
 */
final class ExecutionState
{
    /** @var array<string, mixed> */
    private array $results = [];

    /**
     * Speichert das Ergebnis eines Schritts unter seinem output_key.
     */
    public function set(string $key, mixed $value): void
    {
        $this->results[$key] = $value;
    }

    /**
     * Liest das Ergebnis eines Schritts; null falls nicht vorhanden.
     */
    public function get(string $key): mixed
    {
        return $this->results[$key] ?? null;
    }

    /**
     * Ob unter dem Key ein Ergebnis existiert.
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->results);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->results;
    }

    /**
     * Liefert die Ergebnisse der gegebenen Keys als assoziatives Array
     * (key => result), um sie als Input fuer nachfolgende Schritte zu
     * uebergeben. Fehlende Keys werden ausgelassen.
     *
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    public function collect(array $keys): array
    {
        $collected = [];
        foreach ($keys as $key) {
            if ($this->has($key)) {
                $collected[$key] = $this->get($key);
            }
        }

        return $collected;
    }
}
