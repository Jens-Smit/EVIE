<?php

namespace AppSecurityAuthenticator;

use SymfonyComponentHttpFoundationRedirectResponse;
use SymfonyComponentHttpFoundationRequest;
use SymfonyComponentHttpFoundationResponse;
use SymfonyComponentRoutingGeneratorUrlGeneratorInterface;
use SymfonyComponentSecurityCoreAuthenticationTokenTokenInterface;
use SymfonyComponentSecurityCoreAuthenticatorAbstractAuthenticator;
use SymfonyComponentSecurityCoreAuthenticatorPassportBadgeCsrfTokenBadge;
use SymfonyComponentSecurityCoreAuthenticatorPassportCredentialsPasswordCredentials;
use SymfonyComponentSecurityCoreAuthenticatorPassportPassport;
use SymfonyComponentSecurityCoreExceptionAuthenticationException;
use SymfonyComponentSecurityCoreExceptionCustomUserMessageAuthenticationException;
use SymfonyComponentSecurityHttpAuthenticatorPassportBadgeRememberMeBadge;

class LoginFormAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return $request->isMethod('POST') && $request->getPathInfo() === '/login';
    }

    public function authenticate(Request $request): Passport
    {
        $email = $request->request->get('email');
        $password = $request->request->get('password');
        $csrfToken = $request->request->get('_csrf_token');

        if (!$email || !$password) {
            throw new CustomUserMessageAuthenticationException('Bitte geben Sie E-Mail und Passwort ein.');
        }

        return new Passport(
            new SymfonyComponentSecurityCoreAuthenticatorPassportBadgeUserBadge($email),
            new PasswordCredentials($password),
            [
                new CsrfTokenBadge('authenticate', $csrfToken),
                new RememberMeBadge(),
            ]
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $user = $token->getUser();
        
        if ($user instanceof AppEntityUser) {
            $user->setLastLoginAt(new DateTimeImmutable());
        }

        $targetPath = $request->getSession()->get('_security.main.target_path') ?? '/dashboard';
        
        return new RedirectResponse($this->urlGenerator->generate($targetPath));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $request->getSession()->set('_security.main.last_error', $exception->getMessage());
        return new RedirectResponse($this->urlGenerator->generate('app_login'));
    }
}
