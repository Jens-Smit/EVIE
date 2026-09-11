<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventListener;

use App\EventListener\ObservabilityListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Unit-Tests fuer ObservabilityListener (Request-ID / Trace-ID).
 */
final class ObservabilityListenerTest extends TestCase
{
    private ObservabilityListener $listener;

    protected function setUp(): void
    {
        $this->listener = new ObservabilityListener();
    }

    public function testOnKernelRequestGeneratesIdsWhenAbsent(): void
    {
        $request = new Request();
        $event = $this->createRequestEvent($request);

        $this->listener->onKernelRequest($event);

        self::assertNotNull($request->attributes->get(ObservabilityListener::REQUEST_ID_ATTR));
        self::assertNotNull($request->attributes->get(ObservabilityListener::TRACE_ID_ATTR));
        self::assertSame(
            $request->attributes->get(ObservabilityListener::REQUEST_ID_ATTR),
            $request->attributes->get(ObservabilityListener::TRACE_ID_ATTR),
        );
    }

    public function testOnKernelRequestUsesIncomingHeaders(): void
    {
        $request = Request::create('/', 'GET');
        $request->headers->set('X-Request-ID', 'req-123');
        $request->headers->set('X-Trace-ID', 'trace-456');
        $event = $this->createRequestEvent($request);

        $this->listener->onKernelRequest($event);

        self::assertSame('req-123', $request->attributes->get(ObservabilityListener::REQUEST_ID_ATTR));
        self::assertSame('trace-456', $request->attributes->get(ObservabilityListener::TRACE_ID_ATTR));
    }

    public function testOnKernelRequestIgnoresSubRequests(): void
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = new Request();
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::SUB_REQUEST);

        $this->listener->onKernelRequest($event);

        self::assertFalse($request->attributes->has(ObservabilityListener::REQUEST_ID_ATTR));
    }

    public function testOnKernelResponseSetsHeaders(): void
    {
        $request = new Request();
        $request->attributes->set(ObservabilityListener::REQUEST_ID_ATTR, 'req-1');
        $request->attributes->set(ObservabilityListener::TRACE_ID_ATTR, 'trace-1');
        $response = new Response();
        $event = $this->createResponseEvent($request, $response);

        $this->listener->onKernelResponse($event);

        self::assertSame('req-1', $response->headers->get(ObservabilityListener::REQUEST_ID_HEADER));
        self::assertSame('trace-1', $response->headers->get(ObservabilityListener::TRACE_ID_HEADER));
    }

    public function testOnKernelResponseSkipsMissingAttributes(): void
    {
        $request = new Request();
        $response = new Response();
        $event = $this->createResponseEvent($request, $response);

        $this->listener->onKernelResponse($event);

        self::assertFalse($response->headers->has(ObservabilityListener::REQUEST_ID_HEADER));
        self::assertFalse($response->headers->has(ObservabilityListener::TRACE_ID_HEADER));
    }

    public function testGetRequestIdReturnsNullOutsideRequestScope(): void
    {
        self::assertNull(ObservabilityListener::getRequestId());
    }

    private function createRequestEvent(Request $request): RequestEvent
    {
        $kernel = $this->createMock(HttpKernelInterface::class);

        return new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
    }

    private function createResponseEvent(Request $request, Response $response): ResponseEvent
    {
        $kernel = $this->createMock(HttpKernelInterface::class);

        return new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);
    }
}
