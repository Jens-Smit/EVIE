<?php

declare(strict_types=1);

namespace App\AI\Pipeline\Capability;

/**
 * Value-Object (enum-aehnlich): Entscheidung der Phase 4 ueber eine
 * Faehigkeit pro Schritt.
 *
 *  - Available: Faehigkeit vorhanden; ExecutionReference ist gesetzt.
 *  - Missing:    Faehigkeit fehlt; ToolDefinitionGenerator + HITL noetig.
 *  - Pending:    Tool wurde generiert, wartet auf menschliche Freigabe.
 *
 * @see docs/architecture/orchestrator-pipeline.md Phase 4
 */
enum CapabilityDecision: string
{
    case Available = 'available';
    case Missing = 'missing';
    case Pending = 'pending';

    public function isAvailable(): bool
    {
        return $this === self::Available;
    }

    public function isMissing(): bool
    {
        return $this === self::Missing;
    }

    public function isPending(): bool
    {
        return $this === self::Pending;
    }
}
