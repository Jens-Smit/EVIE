<?php

declare(strict_types=1);

namespace App\AI\Pipeline\Execution;

use App\AI\Pipeline\Plan\Step;
use App\AI\Pipeline\PipelineContext;

/**
 * Phase 5: Fuehrt einen einzelnen Plan-Schritt aus.
 *
 * Der ExecutionCoordinator iteriert deterministisch ueber die Plan-Steps
 * und delegiert jeden Schritt an den Executor, der ihn unterstuetzt
 * (type=tool -> ToolStepExecutor, type=subagent -> SubAgentStepExecutor).
 * Der Executor erhaelt den Workflow-State, um Ergebnisse vorheriger
 * Schritte als Input zu verwenden, und liefert das Schritt-Ergebnis.
 *
 * @see docs/architecture/orchestrator-pipeline.md Phase 5
 */
interface StepExecutorInterface
{
    /**
     * Ob dieser Executor den gegebenen Schritt ausfuehren kann.
     */
    public function supports(Step $step): bool;

    /**
     * Fuehrt den Schritt aus und liefert dessen Ergebnis (string oder
     * array; wird im ExecutionState unter dem output_key abgelegt).
     */
    public function execute(Step $step, PipelineContext $context, ExecutionState $state): mixed;
}
