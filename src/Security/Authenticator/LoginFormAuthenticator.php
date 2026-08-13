<?php

namespace AppSecurityAuthenticator;

use SymfonyComponentHttpFoundationRedirectResponse;
use SymfonyComponentHttpFoundationRequest;
use SymfonyComponentHttpFoundationResponse;
use SymfonyComponentRoutingGeneratorUrlGeneratorInterface;
use SymfonyComponentSecurityCoreAuthenticationTokenTokenInterface;
use SymfonyComponentSecurityCoreAuthenticatorAbstractAuthenticator;
use SymfonyComponentSecurityCoreAuthenticatorPassportBadgeUserBadge;
use SymfonyComponentSecurityCoreAuthenticatorPassportPassport;
use SymfonyComponentSecurityCoreAuthenticatorPassportCredentialsPasswordCredentials;
use SymfonyComponentSecurityCoreExceptionCustomUserMessageAuthenticationException;
use SymfonyComponentSecurityHttpAuthenticatorPassportBadgeRememberMeBadge;
use SymfonyComponentSecurityHttpAuthenticatorPassportBadgeCsrfTokenBadge;
use SymfonyComponentSecurityHttpAuthenticatorPassportCredentialsPasswordCredentials as SymfonyPasswordCredentials;

class LoginFormAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return $request->isMethod('POST') && $request->request->has('email');
    }

    public function authenticate(Request $request): Passport
    {
        $email = $request->request->get('email');
        $password = $request->request->get('password');
        $csrfToken = $request->request->get('_csrf_token');

        return new Passport(
            new UserBadge($email),
            new SymfonyPasswordCredentials($password),
            [
                new CsrfTokenBadge('authenticate', $csrfToken),
                new RememberMeBadge(),
            ]
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return new RedirectResponse($this->urlGenerator->generate('app_home'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new RedirectResponse($this->urlGenerator->generate('app_login'));
    }

    public function start(Request $request, AuthenticationException $authException = null): Response
    {
        return new RedirectResponse($this->urlGenerator->generate('app_login'));
    }
}
