<?php

declare(strict_types=1);

namespace App\AI\Pipeline\Plan;

/**
 * Value-Object: Ein geordneter Plan aus Phasen-Schritten.
 *
 * Phase 3 (Plan) liefert dieses Objekt. Ist der Plan eine Rueckfrage
 * (clarify), beendet er die Pipeline ohne Capability-Generierung.
 *
 * @see docs/architecture/orchestrator-pipeline.md Phase 3
 */
final class Plan
{
    private array $steps;
    private ?string $summary;

    /**
     * @param Step[]    $steps   geordnete Schrittliste
     * @param string|null $summary optionale Zusammenfassung des Plans
     */
    public function __construct(array $steps, ?string $summary = null)
    {
        $this->steps = $steps;
        $this->summary = $summary;
    }

    /**
     * @return Step[]
     */
    public function getSteps(): array
    {
        return $this->steps;
    }

    public function getSummary(): ?string
    {
        return $this->summary;
    }

    /**
     * Ein Plan, der ausschliesslich aus einer Rueckfrage besteht, beendet
     * die Pipeline in Phase 3 (clarify-Exit-Gate) — ohne Capability.
     */
    public function isClarification(): bool
    {
        return count($this->steps) === 1
            && $this->steps[0]->getType() === Step::TYPE_CLARIFY;
    }
}
