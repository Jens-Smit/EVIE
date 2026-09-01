<?php

namespace App\Security\Authenticator;

use App\Entity\Organization;
use App\Entity\User;
use App\Repository\OrganizationRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Security\Authenticator\OAuth2Authenticator;
use League\OAuth2\Client\Provider\ResourceOwnerInterface;
use League\OAuth2\Client\Token\AccessToken;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;

/**
 * OAuth2/OIDC Authenticator fuer SSO-Integration (P2).
 *
 * Unterstuetzt mehrere Provider (Google, GitHub, Azure AD, etc.)
 * und ordnet Benutzer den passenden Organisationen zu.
 */
class OAuthAuthenticator extends OAuth2Authenticator
{
    public const string OAUTH_CHECK_PATH = '/oauth/check';

    public function __construct(
        private ClientRegistry $clientRegistry,
        private EntityManagerInterface $entityManager,
        private UserRepository $userRepository,
        private OrganizationRepository $organizationRepository,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * Unterstuetzte OAuth-Provider.
     */
    public function getOAuthClients(): array
    {
        return array_keys($this->clientRegistry->all());
    }

    /**
     * Prueft, ob die Anfrage von einem OAuth-Callback kommt.
     */
    public function supports(Request $request): ?bool
    {
        // Check if this is an OAuth callback
        $route = $request->attributes->get('_route');
        if (null === $route) {
            return false;
        }

        // OAuth callbacks have routes like: oauth_check_<provider>
        return str_starts_with($route, 'oauth_check_');
    }

    /**
     * Authentifiziert den Benutzer ueber OAuth.
     */
    public function authenticate(Request $request): Passport
    {
        $clientName = $this->extractClientName($request);
        $client = $this->clientRegistry->getClient($clientName);

        // Fetch the access token
        $accessToken = $this->fetchAccessToken($client);

        // Get user info from the provider
        $resourceOwner = $client->fetchUserFromToken($accessToken);

        // Create a user badge with the provider ID
        return new Passport(
            new UserBadge(
                $resourceOwner->getId(),
                function ($userIdentifier) use ($resourceOwner, $clientName) {
                    return $this->loadOrCreateUser($userIdentifier, $resourceOwner, $clientName);
                }
            ),
            // No credentials needed for OAuth
        );
    }

    /**
     * Extrahiert den Client-Namen aus der Anfrage.
     */
    private function extractClientName(Request $request): string
    {
        $route = $request->attributes->get('_route');
        // Route format: oauth_check_<provider>
        return str_replace('oauth_check_', '', $route);
    }

    /**
     * Laedt einen bestehenden Benutzer oder erstellt einen neuen.
     */
    public function loadOrCreateUser(
        string $userIdentifier,
        ResourceOwnerInterface $resourceOwner,
        string $provider
    ): ?User {
        // Pruefe, ob der Benutzer bereits existiert
        $existingUser = $this->userRepository->findOneBy([
            'ssoProvider' => $provider,
            'ssoId' => $userIdentifier,
        ]);

        if (null !== $existingUser) {
            return $existingUser;
        }

        // Pruefe, ob ein Benutzer mit dieser E-Mail existiert
        $email = $resourceOwner->getEmail() ?? $resourceOwner->getId() . '@' . $provider . '.oauth';
        $userByEmail = $this->userRepository->findOneBy(['email' => $email]);

        if (null !== $userByEmail) {
            // Aktualisiere die SSO-Informationen
            $userByEmail->setSsoProvider($provider);
            $userByEmail->setSsoId($userIdentifier);
            $this->entityManager->persist($userByEmail);
            $this->entityManager->flush();
            return $userByEmail;
        }

        // Erstelle einen neuen Benutzer
        $user = new User();
        $user->setEmail($email);
        $user->setSsoProvider($provider);
        $user->setSsoId($userIdentifier);
        
        // Setze Standard-Rollen
        $user->setRoles(['ROLE_USER']);
        
        // Extrahiere Vor- und Nachname
        $firstName = $resourceOwner->getFirstName() ?? '';
        $lastName = $resourceOwner->getLastName() ?? '';
        
        if (!empty($firstName)) {
            $user->setFirstName($firstName);
        }
        if (!empty($lastName)) {
            $user->setLastName($lastName);
        }
        
        // Versuche, eine passende Organisation zu finden
        // (z.B. basierend auf der E-Mail-Domaene)
        $organization = $this->findOrganizationForUser($email, $provider);
        if (null !== $organization) {
            $user->setOrganizationId($organization->getId());
        }

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    /**
     * Finde eine passende Organisation fuer den Benutzer.
     */
    private function findOrganizationForUser(string $email, string $provider): ?Organization
    {
        // Extrahiere die Domain aus der E-Mail
        $domain = $this->extractDomainFromEmail($email);
        
        if (null === $domain) {
            return null;
        }

        // Suche nach einer Organisation mit passender Domain
        $organizations = $this->organizationRepository->findAll();
        
        foreach ($organizations as $organization) {
            $settings = $organization->getSettings() ?? [];
            $domains = $settings['allowed_domains'] ?? [];
            
            if (in_array($domain, $domains, true)) {
                return $organization;
            }
        }

        // Falls keine spezifische Organisation gefunden wurde,
        // gib die erste aktive Organisation zurueck (falls vorhanden)
        foreach ($organizations as $organization) {
            if ($organization->isActive()) {
                return $organization;
            }
        }

        return null;
    }

    /**
     * Extrahiere die Domain aus einer E-Mail-Adresse.
     */
    private function extractDomainFromEmail(string $email): ?string
    {
        $parts = explode('@', $email);
        if (count($parts) === 2) {
            return $parts[1];
        }
        return null;
    }

    /**
     * Erfolgs-Handler: Leite den Benutzer nach der Authentifizierung weiter.
     */
    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $user = $token->getUser();
        
        if ($user instanceof User) {
            // Aktualisiere das letzte Login
            $user->setLastLoginAt(new \DateTimeImmutable());
            $this->entityManager->persist($user);
            $this->entityManager->flush();
        }

        // Speichere die Ziel-URL aus der Session
        $targetPath = $request->getSession()->get('_security.' . $firewallName . '.target_path');
        
        if (is_string($targetPath) && $targetPath !== '') {
            $request->getSession()->remove('_security.' . $firewallName . '.target_path');
            return new RedirectResponse($targetPath);
        }

        // Frontend-Audit F4: Neuer Nutzer ohne abgeschlossenes Onboarding
        if ($user instanceof User && !$user->isOnboardingComplete()) {
            return new RedirectResponse($this->urlGenerator->generate('app_onboarding'));
        }

        return new RedirectResponse($this->urlGenerator->generate('app_home'));
    }

    /**
     * Fehler-Handler.
     */
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $message = strtr($exception->getMessageKey(), $exception->getMessageData());
        $request->getSession()->getFlashBag()->add('error', $message);

        return new RedirectResponse($this->urlGenerator->generate('app_login'));
    }

    /**
     * Startet den OAuth-Flow.
     */
    public function start(Request $request, AuthenticationException $authException = null): Response
    {
        return new RedirectResponse($this->urlGenerator->generate('app_login'));
    }

    /**
     * Generiert die URL fuer den OAuth-Login.
     */
    public function getLoginUrl(string $provider, string $targetPath = null): string
    {
        $client = $this->clientRegistry->getClient($provider);
        
        // Speichere die Ziel-URL in der Session
        if (null !== $targetPath) {
            $this->entityManager->getConnection()->getSession()->set('_security.main.target_path', $targetPath);
        }

        return $client->getAuthorizationUrl([
            'scope' => $this->getScopesForProvider($provider),
        ]);
    }

    /**
     * Liefert die benoetigten Scopes fuer einen Provider.
     */
    private function getScopesForProvider(string $provider): array
    {
        $scopes = [
            'google' => ['openid', 'profile', 'email'],
            'github' => ['user:email', 'user:read'],
            'azure' => ['openid', 'profile', 'email'],
            'gitlab' => ['openid', 'profile', 'email'],
        ];

        return $scopes[$provider] ?? ['openid', 'profile', 'email'];
    }
}
