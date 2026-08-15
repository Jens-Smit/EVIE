<?php

declare(strict_types=1);

namespace App\AI\Security;

/**
 * Policy-Entscheidung für die Tool-Ausführung (Human-in-the-Loop).
 *
 * Entspricht dem nativen Symfony AI Human-in-the-Loop-Pattern:
 *  - Allow:   Tool wird ohne Nachfrage ausgeführt.
 *  - Deny:    Tool wird blockiert (z. B. SSRF, nicht gelisteter Executor).
 *  - AskUser: Tool-Ausführung erfordert eine menschliche Freigabe (HITL).
 *
 * @see https://symfony.com/doc/current/ai/cookbook/human-in-the-loop.html
 */
enum PolicyDecision
{
    case Allow;
    case Deny;
    case AskUser;
}
