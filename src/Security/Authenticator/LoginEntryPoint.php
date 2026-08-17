<?php

namespace App\Security\Authenticator;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * AuthenticationEntryPoint fuer die Haupt-Firewall.
 *
 * Leitet unauthentifizierte Anfragen auf geschuetzte Routen auf /login um
 * (302) statt die rohe Symfony "Unauthorized"-Debug-Seite (401) auszuliefern.
 *
 * Hintergrund (Frontend-Audit F1): Ohne konfigurierten entry_point liefert
 * Symfony Security bei lazy-Firewalls den Default-401-Response, sobald der
 * Authenticator::supports() false zurueckgibt (z.B. bei GET-Requests).
 * Dieser Entry-Point stellt sicher, dass immer ein Redirect zum Login erfolgt.
 */
final class LoginEntryPoint implements AuthenticationEntryPointInterface
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new RedirectResponse($this->urlGenerator->generate('app_login'));
    }
}
