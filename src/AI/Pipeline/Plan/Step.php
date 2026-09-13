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
 * @see docs/architecture/orchestrator-pipeline.md Phase 3
 */
final class Step
{
    public const TYPE_TOOL = 'tool';
    public const TYPE_SUBAGENT = 'subagent';
    public const TYPE_CLARIFY = 'clarify';

    private string $type;
    private string $target;
    private array $parameters;
    private bool $needsCapability;
    private ?string $reason;
    private ?object $executionReference;

    public function __construct(
        string $type,
        string $target,
        array $parameters = [],
        bool $needsCapability = false,
        ?string $reason = null
    ) {
        $this->type = $type;
        $this->target = $target;
        $this->parameters = $parameters;
        $this->needsCapability = $needsCapability;
        $this->reason = $reason;
        $this->executionReference = null;
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
        $clone = new self($this->type, $this->target, $this->parameters, $this->needsCapability, $this->reason);
        $clone->executionReference = $reference;

        return $clone;
    }
}
