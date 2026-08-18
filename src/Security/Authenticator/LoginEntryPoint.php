<?php

namespace App\Security\Authenticator;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * AuthenticationEntryPoint fuer die Haupt-Firewall.
 *
 * Leitet unauthentifizierte Anfragen auf geschuetzte Frontend-Routen auf /login um
 * (302) statt die rohe Symfony "Unauthorized"-Debug-Seite (401) auszuliefern.
 *
 * Fuer API-Anfragen (/api/* oder Accept: application/json) wird stattdessen ein
 * 401 JSON-Response geliefert, damit API-Clients keine HTML-Login-Page erhalten.
 *
 * Hintergrund (Frontend-Audit F1): Ohne konfigurierten entry_point liefert
 * Symfony Security bei lazy-Firewalls den Default-401-Response, sobald der
 * Authenticator::supports() false zurueckgibt (z.B. bei GET-Requests).
 * Dieser Entry-Point stellt sicher, dass Frontend-Anfragen immer einen Redirect
 * zum Login erhalten und API-Anfragen ein sauberes 401 JSON.
 */
final class LoginEntryPoint implements AuthenticationEntryPointInterface
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        // API-Anfragen erhalten 401 JSON (kein Login-Redirect fuer API-Clients).
        if ($this->isApiRequest($request)) {
            return new JsonResponse(
                ['error' => 'Authentication required', 'code' => 401],
                Response::HTTP_UNAUTHORIZED
            );
        }

        // Frontend-Anfragen werden zum Login weitergeleitet (302).
        return new RedirectResponse($this->urlGenerator->generate('app_login'));
    }

    private function isApiRequest(Request $request): bool
    {
        // /api/* Routen sind API-Anfragen.
        if (str_starts_with($request->getPathInfo(), '/api/')) {
            return true;
        }

        // Accept: application/json (oder AJAX) ohne HTML-Accept ist API.
        $accept = $request->headers->get('Accept', '');
        if (str_contains($accept, 'application/json') && !str_contains($accept, 'text/html')) {
            return true;
        }

        return false;
    }
}
