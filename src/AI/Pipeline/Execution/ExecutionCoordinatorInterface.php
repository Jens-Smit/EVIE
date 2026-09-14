<?php

declare(strict_types=1);

namespace App\AI\Pipeline\Execution;

use App\AI\Pipeline\Capability\CapabilityResult;
use App\AI\Pipeline\Plan\Plan;
use App\AI\Pipeline\PipelineContext;

/**
 * Phase 5 + Antwortformatierung: Erzeugt die finale PipelineResult-Antwort.
 *
 * Die Ausfuehrung (execute) nutzt die native Agent-Loop
 * (ToolCallRequested -> HitlListener -> SecurityGuard -> Executor) und
 * protokolliert jeden Schritt via AuditLogger in die AgentHistory. Die
 * Formatierungsmethoden (dialog/clarify/awaitingApproval) erzeugen die
 * User-Antwort fuer die Exit-Gates der Phasen 2-4, ohne eine Capability
 * zu generieren oder eine Aktion auszuloesen.
 *
 * Keine neuen Symfony-AI-Bridges, keine Konstruktor-Injection fuer Tools.
 *
 * @see docs/architecture/orchestrator-pipeline.md Phase 5
 */
interface ExecutionCoordinatorInterface
{
    /**
     * Phase 2 Exit-Gate: direkte Dialog-Antwort (kein Tool, kein HITL).
     */
    public function dialog(PipelineContext $context): PipelineResult;

    /**
     * Phase 3 Exit-Gate: Rueckfrage bei unklarer Anfrage (keine Capability).
     */
    public function clarify(PipelineContext $context, Plan $plan): PipelineResult;

    /**
     * Phase 4 Exit-Gate: Capability fehlt oder wartet auf Freigabe (HITL).
     */
    public function awaitingApproval(PipelineContext $context, CapabilityResult $result): PipelineResult;

    /**
     * Phase 5: Ausfuehrung eines freigegebenen Plans ueber die Agent-Loop.
     */
    public function execute(PipelineContext $context, Plan $plan): PipelineResult;
}
