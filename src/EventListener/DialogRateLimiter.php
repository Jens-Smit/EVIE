<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Rate-Limiter fuer Agent- und MCP-API-Endpoints (P1-5, M-8).
 *
 * Wendet den konfigurierten `agent_web_actions` Rate-Limiter (sliding window,
 * Default 10 Calls / 10 Min) auf POST /api/agent/dialog an, sodass ein einzelner
 * Client nicht unkontrolliert Mistral-API-Kosten verursachen oder den
 * Agent-Loop in Endlosschleifen treiben kann.
 *
 * M-8: Zusaetzlich wird der `mcp_api_calls` Rate-Limiter (Default 30 Calls /
 * 10 Min) auf die MCP-API-Endpunkte unter /api/mcp/servers/... angewandt
 * (Tool-Ausfuehrung und Tool-Listing), um Missbrauch/Kosten und Brute-Force
 * gegen Tool-Argumente zu begrenzen.
 *
 * Der eigentliche Tool-Call-Hardlimit im Agent-Loop wird durch Symfony AI's
 * `max_tool_calls` (Default 50, hier ueber ToolCallLimitProcessor pro Request
 * weiter begrenzt) erzwungen. Dieser Listener deckt die HTTP-Ebene ab.
 */
final class DialogRateLimiter
{
    public function __construct(
        private readonly RateLimiterFactory $agentWebActionsLimiter,
        private readonly RateLimiterFactory $mcpApiCallsLimiter,
    ) {
    }

        #[AsEventListener(event: RequestEvent::class, priority: 10)]
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();

        // Nur die relevanten API-Endpoints limitieren; andere Routen sind unbetroffen.
        if (str_starts_with($path, '/api/agent/dialog')) {
            $this->limit($this->agentWebActionsLimiter, $request, 'Agent-Dialog');
            return;
        }

        // M-8: MCP-API-Endpoints (Tool-Listing und Tool-Ausfuehrung).
        if (str_starts_with($path, '/api/mcp/servers/')) {
            $this->limit($this->mcpApiCallsLimiter, $request, 'MCP-API');
        }
    }

    private function limit(RateLimiterFactory $factory, Request $request, string $label): void
    {
        $limiter = $factory->create($request->getClientIp() ?? 'unknown');
        $limit = $limiter->consume(1);
        if (!$limit->isAccepted()) {
            throw new TooManyRequestsHttpException(
                $limit->getRetryAfter()->getTimestamp() - time(),
                sprintf('Rate-Limit fuer %s erreicht. Bitte spaeter erneut versuchen.', $label),
            );
        }
    }
}
