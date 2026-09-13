<?php

declare(strict_types=1);

namespace App\AI\Pipeline;

/**
 * Orchestriert die fuenf Phasen Goal -> Intent -> Plan -> Capability ->
 * Execution. Jede User-Anfrage durchlaeuft die Phasen in dieser Reihenfolge;
 * keine Phase darf uebersprungen werden. Capability Discovery/Generierung
 * findet ausschliesslich in Phase 4 statt.
 *
 * @see docs/architecture/orchestrator-pipeline.md
 */
interface PipelineInterface
{
    public function run(string $message, string $userIdentifier): Execution\PipelineResult;
}
