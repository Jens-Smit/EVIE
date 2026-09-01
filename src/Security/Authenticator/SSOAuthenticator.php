<?php

namespace App\Security\Authenticator;

use App\Entity\User;
use App\Entity\Organization;
use App\Repository\UserRepository;
use App\Repository\OrganizationRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;

/**
 * SSO-Authenticator fuer manuelle Token-basierte Authentifizierung.
 * Unterstuetzt OAuth2/OIDC-Tokens ohne externe Bundles.
 * Kompatibel mit Symfony-AI und der bestehenden Organization-Entity.
 */
class SSOAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private EntityManagerInterface $entityManager,
        private UserRepository $userRepository,
        private OrganizationRepository $organizationRepository,
    ) {
    }

    /**
     * Prueft, ob die Anfrage eine SSO-Anmeldung ist.
     */
    public function supports(Request $request): ?bool
    {
        return $request->isMethod('POST') && $request->request->has('sso_token');
    }

    /**
     * Authentifiziert den Benutzer mit einem SSO-Token.
     * Das Token kann ein JWT oder ein einfacher API-Key sein.
     */
    public function authenticate(Request $request): Passport
    {
        $token = (string) $request->request->get('sso_token', '');
        $provider = (string) $request->request->get('sso_provider', 'custom');
        $csrfToken = (string) $request->request->get('_csrf_token', '');

        return new Passport(
            new UserBadge($token, function ($userIdentifier) use ($token, $provider) {
                return $this->validateAndLoadUser($userIdentifier, $token, $provider);
            }),
            new PasswordCredentials(''), // Kein Passwort benoetigt fuer Token-Auth
            [
                new CsrfTokenBadge('sso_authenticate', $csrfToken),
                new RememberMeBadge(),
            ]
        );
    }

    /**
     * Validiert das SSO-Token und laedt oder erstellt den Benutzer.
     */
    private function validateAndLoadUser(string $userIdentifier, string $token, string $provider): ?User
    {
        // Versuche, einen bestehenden Benutzer mit diesem SSO-Token zu finden
        $existingUser = $this->userRepository->findOneBy([
            'ssoProvider' => $provider,
            'ssoId' => $userIdentifier,
        ]);

        if (null !== $existingUser) {
            return $existingUser;
        }

        // Dekodiere das Token, um Benutzerinformationen zu extrahieren
        $userData = $this->decodeToken($token);
        
        if (null === $userData) {
            return null;
        }

        // Extrahiere E-Mail aus dem Token
        $email = $userData['email'] ?? $userIdentifier . '@' . $provider;
        
        // Pruefe, ob ein Benutzer mit dieser E-Mail existiert
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
        $user->setRoles(['ROLE_USER']);
        
        // Setze Vor- und Nachname aus dem Token
        if (isset($userData['first_name'])) {
            $user->setFirstName($userData['first_name']);
        }
        if (isset($userData['last_name'])) {
            $user->setLastName($userData['last_name']);
        }
        
        // Versuche, eine passende Organisation zu finden
        $organization = $this->findOrganizationForUser($email, $provider);
        if (null !== $organization) {
            $user->setOrganizationId($organization->getId());
        }

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    /**
     * Dekodiert ein JWT- oder einfaches Token.
     */
    private function decodeToken(string $token): ?array
    {
        // Versuche, als JWT zu dekodieren
        $parts = explode('.', $token);
        if (count($parts) === 3) {
            try {
                $payload = base64_decode(strtr($parts[1], '-_', '+/') . str_repeat('=', 3 - (3 + strlen($parts[1])) % 4));
                if ($payload !== false) {
                    $data = json_decode($payload, true);
                    if (is_array($data)) {
                        return $data;
                    }
                }
            } catch (\Exception $e) {
                // Ignoriere Fehler beim Dekodieren
            }
        }

        // Falls kein JWT, versuche als JSON zu parsen
        try {
            $data = json_decode($token, true);
            if (is_array($data)) {
                return $data;
            }
        } catch (\Exception $e) {
            // Ignoriere Fehler
        }

        return null;
    }

    /**
     * Finde eine passende Organisation fuer den Benutzer.
     */
    private function findOrganizationForUser(string $email, string $provider): ?Organization
    {
        $domain = $this->extractDomainFromEmail($email);
        
        if (null === $domain) {
            return null;
        }

        $organizations = $this->organizationRepository->findAll();
        
        foreach ($organizations as $organization) {
            $settings = $organization->getSettings() ?? [];
            $domains = $settings['allowed_domains'] ?? [];
            
            if (in_array($domain, $domains, true)) {
                return $organization;
            }
        }

        // Falls keine spezifische Organisation gefunden wurde,
        // gib die erste aktive Organisation zurueck
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
     * Erfolgs-Handler.
     */
    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $user = $token->getUser();
        
        if ($user instanceof User) {
            $user->setLastLoginAt(new DateTimeImmutable());
            $this->entityManager->persist($user);
            $this->entityManager->flush();
        }

        $targetPath = $request->request->get('_target_path');
        if (is_string($targetPath) && $targetPath !== '') {
            return new RedirectResponse($targetPath);
        }

        // Frontend-Audit: Neuer Nutzer ohne abgeschlossenes Onboarding
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
     * Startet den Authentifizierungsprozess.
     */
    public function start(Request $request, AuthenticationException $authException = null): Response
    {
        return new RedirectResponse($this->urlGenerator->generate('app_login'));
    }
}
