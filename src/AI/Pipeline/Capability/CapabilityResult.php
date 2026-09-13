<?php

declare(strict_types=1);

namespace App\AI\Pipeline\Capability;

/**
 * Value-Object: Ergebnis der Capability-Aufloesung fuer einen Schritt.
 *
 * Kapselt die CapabilityDecision zusammen mit der nativen
 * ExecutionReference (sofern verfuegbar) und ggf. der ToolDefinition,
 * die einer HITL-Freigabe bedarf. Reine Datenstruktur, keine Logik.
 *
 * @see docs/architecture/orchestrator-pipeline.md Phase 4
 */
final class CapabilityResult
{
    private CapabilityDecision $decision;
    private ?object $executionReference;
    private ?int $toolDefinitionId;

    public function __construct(
        CapabilityDecision $decision,
        ?object $executionReference = null,
        ?int $toolDefinitionId = null
    ) {
        $this->decision = $decision;
        $this->executionReference = $executionReference;
        $this->toolDefinitionId = $toolDefinitionId;
    }

    public function getDecision(): CapabilityDecision
    {
        return $this->decision;
    }

    public function getExecutionReference(): ?object
    {
        return $this->executionReference;
    }

    public function getToolDefinitionId(): ?int
    {
        return $this->toolDefinitionId;
    }
}
