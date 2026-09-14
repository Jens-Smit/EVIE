<?php

declare(strict_types=1);

namespace App\AI\Pipeline\Intent;

/**
 * Value-Object (enum-aehnlich): Der Intent einer einzelnen Nachricht.
 *
 * Phase 2 (Intent) liefert einen dieser Werte. Nur hier wird entschieden,
 * ob der Pipeline-Lauf als Dialog endet (conversation/information) oder
 * weiterlaeuft (task/unclear). Capability-Generierung wird nie durch den
 * Intent allein ausgeloest.
 *
 * @see docs/architecture/orchestrator-pipeline.md Phase 2
 */
enum Intent: string
{
    case Conversation = 'conversation';
    case Information = 'information';
    case Task = 'task';
    case Unclear = 'unclear';

    /**
     * Dialog-Intents beenden die Pipeline mit einer direkten Textantwort.
     */
    public function isDialog(): bool
    {
        return $this === self::Conversation || $this === self::Information;
    }
}
