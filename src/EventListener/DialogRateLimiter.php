<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Rate-Limiter fuer den Agent-Dialog-Endpoint (P1-5).
 *
 * Wendet den konfigurierten `agent_web_actions` Rate-Limiter (sliding window,
 * Default 10 Calls / 10 Min) auf POST /api/agent/dialog an, sodass ein einzelner
 * Client nicht unkontrolliert Mistral-API-Kosten verursachen oder den
 * Agent-Loop in Endlosschleifen treiben kann.
 *
 * Der eigentliche Tool-Call-Hardlimit im Agent-Loop wird durch Symfony AI's
 * `max_tool_calls` (Default 50, hier ueber ToolCallLimitProcessor pro Request
 * weiter begrenzt) erzwungen. Dieser Listener deckt die HTTP-Ebene ab.
 */
final class DialogRateLimiter
{
    public function __construct(
        private readonly RateLimiterFactory $agentWebActionsLimiter,
    ) {
    }

        #[AsEventListener(event: RequestEvent::class, priority: 10)]
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        // Nur den Dialog-Endpoint limitieren; andere Routen sind unbetroffen.
        if (!str_starts_with($request->getPathInfo(), '/api/agent/dialog')) {
            return;
        }

        $limiter = $this->agentWebActionsLimiter->create($request->getClientIp() ?? 'unknown');
        $limit = $limiter->consume(1);
        if (!$limit->isAccepted()) {
            // 429 Too Many Requests, inkl. Retry-After-Header (Sekunden bis
            // zur naechsten verfuegbaren Anfrage).
            throw new TooManyRequestsHttpException(
                $limit->getRetryAfter()->getTimestamp() - time(),
                'Rate-Limit fuer Agent-Dialog erreicht. Bitte spaeter erneut versuchen.',
            );
        }
    }
}
