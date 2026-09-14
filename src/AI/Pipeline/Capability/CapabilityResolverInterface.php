<?php

declare(strict_types=1);

namespace App\AI\Pipeline\Capability;

use App\AI\Pipeline\Plan\Step;
use App\AI\Pipeline\PipelineContext;

/**
 * Phase 4: Loest pro Schritt, ob die benoetigte Faehigkeit vorhanden ist.
 *
 *  - vorhanden  -> CapabilityResult(Available) mit nativer ExecutionReference
 *  - fehlt      -> ToolDefinitionGenerator + HITL -> CapabilityResult(Missing/Pending)
 *
 * Capability Discovery/Generierung findet ausschliesslich hier statt,
 * nie in Phase 2 oder 3. Keine Konstruktor-Injection fuer Tools; die
 * Faehigkeit wird ueber die native DynamicToolbox zur Laufzeit
 * registriert.
 *
 * @see docs/architecture/orchestrator-pipeline.md Phase 4
 */
interface CapabilityResolverInterface
{
    public function resolve(Step $step, PipelineContext $context): CapabilityResult;
}
