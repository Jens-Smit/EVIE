<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventListener;

use App\EventListener\SecurityHeadersListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Security-Headers-Listener-Tests (Phase 1.2 / Audit-Finding #10).
 *
 * Verifiziert, dass produktionsrelevante Security-Header auf jede
 * Haupt-Response gesetzt werden und bestehende Header nicht ueberschrieben
 * werden.
 */
final class SecurityHeadersListenerTest extends TestCase
{
    private SecurityHeadersListener $listener;

    protected function setUp(): void
    {
        $this->listener = new SecurityHeadersListener();
    }

    public function testSetsSecurityHeadersOnMainResponse(): void
    {
        $event = $this->createResponseEvent(new Request(), new Response());

        $this->listener->onKernelResponse($event);

        $headers = $event->getResponse()->headers;
        self::assertSame('nosniff', $headers->get('X-Content-Type-Options'));
        self::assertSame('DENY', $headers->get('X-Frame-Options'));
        self::assertSame('no-referrer', $headers->get('Referrer-Policy'));
        self::assertNotNull($headers->get('Permissions-Policy'));
        self::assertStringContainsString('geolocation=()', (string) $headers->get('Permissions-Policy'));
    }

    public function testDoesNotSetHstsOverHttp(): void
    {
        $request = Request::create('http://example.com/');
        $event = $this->createResponseEvent($request, new Response());

        $this->listener->onKernelResponse($event);

        self::assertNull($event->getResponse()->headers->get('Strict-Transport-Security'));
    }

    public function testSetsHstsOverHttps(): void
    {
        $request = Request::create('https://example.com/');
        $event = $this->createResponseEvent($request, new Response());

        $this->listener->onKernelResponse($event);

        $hsts = $event->getResponse()->headers->get('Strict-Transport-Security');
        self::assertNotNull($hsts);
        self::assertStringContainsString('max-age=31536000', $hsts);
        self::assertStringContainsString('includeSubDomains', $hsts);
    }

    public function testDoesNotOverrideExistingHeaders(): void
    {
        $response = new Response();
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');

        $event = $this->createResponseEvent(new Request(), $response);

        $this->listener->onKernelResponse($event);

        self::assertSame('SAMEORIGIN', $event->getResponse()->headers->get('X-Frame-Options'));
    }

    public function testIgnoresSubRequests(): void
    {
        $request = Request::create('http://example.com/');
        $response = new Response();
        $kernel = $this->createMock(HttpKernelInterface::class);
        // Sub-Request (zweites Argument = false)
        $event = new ResponseEvent($kernel, $request, HttpKernelInterface::SUB_REQUEST, $response);

        $this->listener->onKernelResponse($event);

        self::assertNull($response->headers->get('X-Content-Type-Options'));
        self::assertNull($response->headers->get('X-Frame-Options'));
    }

    private function createResponseEvent(Request $request, Response $response): ResponseEvent
    {
        $kernel = $this->createMock(HttpKernelInterface::class);

        return new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);
    }
}
