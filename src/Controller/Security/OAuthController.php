<?php

namespace App\Controller\Security;

use App\Security\Authenticator\OAuthAuthenticator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Controller fuer OAuth-Login-Routen.
 * Leitet den Benutzer zum OAuth-Provider weiter.
 */
class OAuthController extends AbstractController
{
    public function __construct(
        private OAuthAuthenticator $oauthAuthenticator,
    ) {
    }

    /**
     * Leitet den Benutzer zum OAuth-Provider weiter.
     */
    #[Route('/oauth/login/{provider}', name: 'oauth_login', methods: ['GET'])]
    public function login(Request $request, string $provider): RedirectResponse
    {
        // Ueberpruefe, ob der Provider unterstuetzt wird
        $availableClients = $this->oauthAuthenticator->getOAuthClients();
        
        if (!in_array($provider, $availableClients, true)) {
            throw $this->createNotFoundException(sprintf('OAuth-Provider "%s" ist nicht konfiguriert.', $provider));
        }

        // Hole die Ziel-URL aus der Anfrage
        $targetPath = $request->query->get('target_path');
        
        // Generiere die OAuth-Login-URL
        $loginUrl = $this->oauthAuthenticator->getLoginUrl($provider, $targetPath);

        return new RedirectResponse($loginUrl);
    }
}
