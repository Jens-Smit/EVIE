<?php

declare(strict_types=1);

namespace App\AI\Pipeline\Plan;

/**
 * Value-Object: Ein einzelner Schritt innerhalb eines Plans.
 *
 * Phase 3 (Plan) erzeugt geordnete Step-Listen. Der Planner darf nur
 * gegen bereits verfuegbare Sub-Agenten/Tools planen; eine fehlende
 * Faehigkeit wird als needs_capability markiert und in Phase 4
 * behandelt. Der Schritt selbst loest keine Aktion aus.
 *
 * Workflow-Felder (Phase 5): id identifiziert den Schritt innerhalb des
 * Plans, depends_on listet die IDs der Schritte, die vor diesem Schritt
 * ausgefuehrt sein muessen, input_from die Output-Keys, deren Ergebnisse
 * als Input uebergeben werden, und output_key den Key, unter dem das
 * Ergebnis dieses Schritts im ExecutionState abgelegt wird. Alle Felder
 * sind optional; fehlende IDs werden automatisch erzeugt.
 *
 * @see docs/architecture/orchestrator-pipeline.md Phase 3
 */
final class Step
{
    public const TYPE_TOOL = 'tool';
    public const TYPE_SUBAGENT = 'subagent';
    public const TYPE_CLARIFY = 'clarify';

    private static int $autoIncrement = 0;

    private string $id;
    private string $type;
    private string $target;
    private array $parameters;
    private bool $needsCapability;
    private ?string $reason;
    private ?object $executionReference;
    /** @var list<string> */
    private array $dependsOn;
    /** @var list<string> */
    private array $inputFrom;
    private ?string $outputKey;

    /**
     * @param list<string> $dependsOn
     * @param list<string> $inputFrom
     */
    public function __construct(
        string $type,
        string $target,
        array $parameters = [],
        bool $needsCapability = false,
        ?string $reason = null,
        ?string $id = null,
        array $dependsOn = [],
        array $inputFrom = [],
        ?string $outputKey = null
    ) {
        $this->id = $id !== null && $id !== '' ? $id : self::generateAutoId($target);
        $this->type = $type;
        $this->target = $target;
        $this->parameters = $parameters;
        $this->needsCapability = $needsCapability;
        $this->reason = $reason;
        $this->executionReference = null;
        $this->dependsOn = $dependsOn;
        $this->inputFrom = $inputFrom;
        $this->outputKey = $outputKey;
    }

    private static function generateAutoId(string $target): string
    {
        $slug = preg_replace('/[^a-z0-9_]+/', '_', strtolower($target)) ?: 'step';

        return sprintf('%s_%d', rtrim($slug, '_'), ++self::$autoIncrement);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getTarget(): string
    {
        return $this->target;
    }

    public function getParameters(): array
    {
        return $this->parameters;
    }

    public function needsCapability(): bool
    {
        return $this->needsCapability;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    /**
     * @return list<string>
     */
    public function getDependsOn(): array
    {
        return $this->dependsOn;
    }

    /**
     * @return list<string>
     */
    public function getInputFrom(): array
    {
        return $this->inputFrom;
    }

    public function getOutputKey(): ?string
    {
        return $this->outputKey;
    }

    public function resolvedOutputKey(): string
    {
        return $this->outputKey ?? $this->id;
    }

    public function getExecutionReference(): ?object
    {
        return $this->executionReference;
    }

    /**
     * Gibt eine neue Step-Instanz mit der gesetzten ExecutionReference
     * zurueck. Wird in Phase 4 aufgerufen, sobald die Capability
     * verfuegbar ist (immutable Step).
     */
    public function withExecutionReference(object $reference): self
    {
        $clone = new self(
            $this->type,
            $this->target,
            $this->parameters,
            $this->needsCapability,
            $this->reason,
            $this->id,
            $this->dependsOn,
            $this->inputFrom,
            $this->outputKey
        );
        $clone->executionReference = $reference;

        return $clone;
    }
}
