<?php

declare(strict_types=1);

namespace App\AI\Pipeline\Goal;

use App\AI\Pipeline\PipelineContext;

/**
 * Phase 1: Liefert das uebergeordnete Ziel fuer einen Pipeline-Lauf.
 *
 * Eine Implementierung nutzt das bestehende AgentGoal-Entity (autonome
 * Ziele via RunAgentGoalMessage) oder leitet ein ad-hoc-Ziel aus der
 * Nachricht ab. Keine Tool-Generierung, kein HITL.
 *
 * @see docs/architecture/orchestrator-pipeline.md Phase 1
 */
interface GoalResolverInterface
{
    public function resolve(PipelineContext $context): Goal;
}
