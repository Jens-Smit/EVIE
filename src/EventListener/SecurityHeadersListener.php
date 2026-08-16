<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Security-Headers-Listener (Phase 1.2 / Audit-Finding #10).
 *
 * Setzt produktionsrelevante Security-Response-Header auf jede Haupt-Response,
 * sofern sie nicht bereits gesetzt wurden (Defense-in-Depth, OWASP-Empfehlung):
 *
 *  - X-Content-Type-Options: nosniff        (MIME-Sniffing verhindern)
 *  - X-Frame-Options: DENY                  (Clickjacking verhindern)
 *  - Referrer-Policy: no-referrer           (Referrer-Leak verhindern)
 *  - Permissions-Policy                     (Feature-Policy restriktiv)
 *  - Strict-Transport-Security              (nur ueber HTTPS, HSTS)
 *
 * Content-Security-Policy bewusst nicht hier: EVIE rendert dynamische
 * HTMX-Fragmente und inline-Scripts; eine strikte CSP wuerde das Frontend
 * brechen. Eine passgenaue CSP ist ein separater Punkt und muss mit dem
 * Frontend zusammen entwickelt werden.
 */
#[AsEventListener(event: ResponseEvent::class, method: 'onKernelResponse', priority: 0)]
final class SecurityHeadersListener
{
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $response = $event->getResponse();

        // Header nur setzen, wenn nicht bereits vom Controller/Nginx vorbelegt.
        if (!$response->headers->has('X-Content-Type-Options')) {
            $response->headers->set('X-Content-Type-Options', 'nosniff');
        }

        if (!$response->headers->has('X-Frame-Options')) {
            $response->headers->set('X-Frame-Options', 'DENY');
        }

        if (!$response->headers->has('Referrer-Policy')) {
            $response->headers->set('Referrer-Policy', 'no-referrer');
        }

        if (!$response->headers->has('Permissions-Policy')) {
            // Restriktiv: alle sensitiven Browser-Features deaktivieren.
            $response->headers->set(
                'Permissions-Policy',
                'geolocation=(), microphone=(), camera=(), payment=(), usb=(), magnetometer=(), gyroscope=(), accelerometer=()',
            );
        }

        // HSTS nur ueber HTTPS (auch hinter einem TLS-terminierenden Proxy,
        // da dasSchema anhand des X-Forwarded-Proto-Headers aufgeloest wird,
        // sofern trusted_proxies konfiguriert sind).
        if ($event->getRequest()->isSecure() && !$response->headers->has('Strict-Transport-Security')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
    }
}
