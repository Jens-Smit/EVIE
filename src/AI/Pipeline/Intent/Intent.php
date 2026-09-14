<?php

declare(strict_types=1);

namespace App\AI\Pipeline\Intent;

/**
 * Value-Object (enum-aehnlich): Der Intent einer einzelnen Nachricht.
 *
 * Phase 2 (Intent) liefert einen dieser Werte. Nur hier wird entschieden,
 * ob der Pipeline-Lauf als Dialog endet (conversation/information) oder
 * weiterlaeuft (task/setup_task/unclear). Capability-Generierung wird nie
 * durch den Intent allein ausgeloest.
 *
 * SetupTask ist der Intent fuer mehrstufige Aufbau-Aufgaben (z.B. CEO-Agent,
 * Unternehmensaufbau, mehrstufiges Setup mit Sub-Agenten-Koordination). Er
 * endet NICHT als Dialog, sondern laeuft in Phase 3 (Plan) weiter, wo ein
 * persistenter Plan mit AgentGoal + Aufgabenschritten erzeugt wird.
 *
 * @see docs/architecture/orchestrator-pipeline.md Phase 2
 */
enum Intent: string
{
    case Conversation = 'conversation';
    case Information = 'information';
    case Task = 'task';
    case SetupTask = 'setup_task';
    case Unclear = 'unclear';

    /**
     * Dialog-Intents beenden die Pipeline mit einer direkten Textantwort.
     */
    public function isDialog(): bool
    {
        return $this === self::Conversation || $this === self::Information;
    }

    /**
     * Plan-Intents laufen weiter in Phase 3 (Plan) und erzeugen einen
     * geordneten Plan, der ggf. persistente Aufgaben/AgentGoals anlegt.
     */
    public function requiresPlan(): bool
    {
        return $this === self::Task || $this === self::SetupTask;
    }
}
