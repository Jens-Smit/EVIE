<?php

declare(strict_types=1);

namespace App\AI\Pipeline\Plan;

use App\AI\Pipeline\Intent\Intent;
use App\AI\Pipeline\PipelineContext;

/**
 * Phase 3: Erzeugt einen geordneten Plan aus Intent und Kontext.
 *
 * Der Planner ist ein reiner LLM-Aufruf (kein Tool-Calling) und darf nur
 * gegen bereits verfuegbare Sub-Agenten/Tools planen. Fehlende Faehigkeiten
 * werden als Step{needs_capability: true} markiert und in Phase 4
 * behandelt; hier wird nichts erfunden oder generiert.
 *
 * @see docs/architecture/orchestrator-pipeline.md Phase 3
 */
interface PlannerInterface
{
    public function plan(PipelineContext $context, Intent $intent): Plan;
}
