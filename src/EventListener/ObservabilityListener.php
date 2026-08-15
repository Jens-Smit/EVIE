<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\Uid\Uuid;

/**
 * Observability-Listener (P2-10): injiziert Request-ID/Trace-ID in jeden
 * Request und Response-Header für korreliertes Logging/Tracing.
 *
 * Request-ID: eindeutig pro HTTP-Request.
 * Trace-ID: kann vom Aufrufer übergeben werden (X-Trace-ID), sonst generiert.
 */
#[AsEventListener(event: RequestEvent::class, method: 'onKernelRequest', priority: 20)]
#[AsEventListener(event: ResponseEvent::class, method: 'onKernelResponse', priority: 20)]
final class ObservabilityListener
{
    public const REQUEST_ID_HEADER = 'X-Request-ID';
    public const TRACE_ID_HEADER = 'X-Trace-ID';
    public const REQUEST_ID_ATTR = '_evie_request_id';
    public const TRACE_ID_ATTR = '_evie_trace_id';

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        $requestId = $request->headers->get(self::REQUEST_ID_HEADER) ?: (string) Uuid::v4();
        $traceId = $request->headers->get(self::TRACE_ID_HEADER) ?: $requestId;

        $request->attributes->set(self::REQUEST_ID_ATTR, $requestId);
        $request->attributes->set(self::TRACE_ID_ATTR, $traceId);
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $response = $event->getResponse();

        $requestId = $request->attributes->get(self::REQUEST_ID_ATTR);
        $traceId = $request->attributes->get(self::TRACE_ID_ATTR);

        if (null !== $requestId) {
            $response->headers->set(self::REQUEST_ID_HEADER, $requestId);
        }
        if (null !== $traceId) {
            $response->headers->set(self::TRACE_ID_HEADER, $traceId);
        }
    }

    public static function getRequestId(): ?string
    {
        $request = null;

        return $request?->attributes->get(self::REQUEST_ID_ATTR);
    }
}
