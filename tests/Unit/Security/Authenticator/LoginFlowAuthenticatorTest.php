<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security\Authenticator;

use App\Entity\User;
use App\Security\Authenticator\LoginFormAuthenticator;
use App\Security\Authenticator\LoginEntryPoint;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;

/**
 * Unit-Tests fuer LoginFormAuthenticator und LoginEntryPoint.
 *
 * Deckt supports, authenticate, onAuthenticationSuccess (alle Zweige),
 * onAuthenticationFailure, start und die Accept-Header-basierte API-Erkennung
 * des LoginEntryPoint ab.
 */
final class LoginFlowAuthenticatorTest extends TestCase
{
    private UrlGeneratorInterface $urlGenerator;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $this->urlGenerator->method('generate')->willReturnCallback(
            static fn (string $route) => match ($route) {
                'app_login' => '/login',
                'app_home' => '/',
                'app_onboarding' => '/onboarding',
                default => '/' . $route,
            }
        );
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->entityManager->expects(self::any())->method('persist');
        $this->entityManager->expects(self::any())->method('flush');
    }

    public function testSupportsTrueForPostWithEmail(): void
    {
        $auth = new LoginFormAuthenticator($this->urlGenerator, $this->entityManager);
        $request = Request::create('/login', 'POST', ['email' => 'a@b.de', 'password' => 'x']);
        self::assertTrue($auth->supports($request));
    }

    public function testSupportsFalseForGet(): void
    {
        $auth = new LoginFormAuthenticator($this->urlGenerator, $this->entityManager);
        $request = Request::create('/login', 'GET', ['email' => 'a@b.de']);
        self::assertFalse($auth->supports($request));
    }

    public function testAuthenticateBuildsPassportWithCredentials(): void
    {
        $auth = new LoginFormAuthenticator($this->urlGenerator, $this->entityManager);
        $request = Request::create('/login', 'POST', [
            'email' => 'user@test.de',
            'password' => 'secret',
            '_csrf_token' => 'tok',
        ]);
        $passport = $auth->authenticate($request);
        self::assertInstanceOf(Passport::class, $passport);
        self::assertSame('user@test.de', $passport->getBadge('Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge')->getUserIdentifier());
    }

    public function testOnAuthenticationSuccessRedirectsToHomeForOnboardedUser(): void
    {
        $auth = new LoginFormAuthenticator($this->urlGenerator, $this->entityManager);
        $user = (new User())->setEmail('u@t.de')->setOnboardingComplete(true);
        $token = new UsernamePasswordToken($user, 'main', ['ROLE_USER']);
        $request = Request::create('/login', 'POST');
        $response = $auth->onAuthenticationSuccess($request, $token, 'main');
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/', $response->getTargetUrl());
    }

    public function testOnAuthenticationSuccessRedirectsToOnboardingForNewUser(): void
    {
        $auth = new LoginFormAuthenticator($this->urlGenerator, $this->entityManager);
        $user = (new User())->setEmail('u@t.de')->setOnboardingComplete(false);
        $token = new UsernamePasswordToken($user, 'main', ['ROLE_USER']);
        $request = Request::create('/login', 'POST');
        $response = $auth->onAuthenticationSuccess($request, $token, 'main');
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/onboarding', $response->getTargetUrl());
    }

    public function testOnAuthenticationSuccessRedirectsToTargetPath(): void
    {
        $auth = new LoginFormAuthenticator($this->urlGenerator, $this->entityManager);
        $user = (new User())->setEmail('u@t.de')->setOnboardingComplete(true);
        $token = new UsernamePasswordToken($user, 'main', ['ROLE_USER']);
        $request = Request::create('/login', 'POST', ['_target_path' => '/dashboard']);
        $response = $auth->onAuthenticationSuccess($request, $token, 'main');
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/dashboard', $response->getTargetUrl());
    }

    public function testOnAuthenticationFailureRedirectsToLogin(): void
    {
        $auth = new LoginFormAuthenticator($this->urlGenerator, $this->entityManager);
        $request = Request::create('/login', 'POST');
        $response = $auth->onAuthenticationFailure($request, new AuthenticationException('bad'));
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/login', $response->getTargetUrl());
    }

    public function testStartRedirectsToLogin(): void
    {
        $auth = new LoginFormAuthenticator($this->urlGenerator, $this->entityManager);
        $response = $auth->start(Request::create('/protected'));
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/login', $response->getTargetUrl());
    }

    public function testEntryPointReturnsJsonForApiPath(): void
    {
        $entry = new LoginEntryPoint($this->urlGenerator);
        $response = $entry->start(Request::create('/api/something'));
        self::assertSame(401, $response->getStatusCode());
        self::assertJson($response->getContent());
    }

    public function testEntryPointReturnsJsonForJsonAcceptWithoutHtml(): void
    {
        $entry = new LoginEntryPoint($this->urlGenerator);
        $request = Request::create('/protected');
        $request->headers->set('Accept', 'application/json');
        $response = $entry->start($request);
        self::assertSame(401, $response->getStatusCode());
        self::assertJson($response->getContent());
    }

    public function testEntryPointRedirectsToLoginForFrontendRequest(): void
    {
        $entry = new LoginEntryPoint($this->urlGenerator);
        $request = Request::create('/protected');
        $request->headers->set('Accept', 'text/html');
        $response = $entry->start($request, new AuthenticationException('no auth'));
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/login', $response->getTargetUrl());
    }
}
