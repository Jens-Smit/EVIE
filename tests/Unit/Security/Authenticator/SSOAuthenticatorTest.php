<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security\Authenticator;

use App\Entity\Organization;
use App\Entity\User;
use App\Repository\OrganizationRepository;
use App\Repository\UserRepository;
use App\Security\Authenticator\SSOAuthenticator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockFileSessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Vollstaendige Test-Abdeckung fuer SSOAuthenticator.
 *
 * Verifiziert supports, authenticate + UserBadge-Callback (validateAndLoadUser),
 * Token-Decoding (JWT + JSON + invalid), Organisations-Zuordnung,
 * onAuthenticationSuccess/Failure und start.
 */
final class SSOAuthenticatorTest extends TestCase
{
    private function createAuthenticator(
        ?UserRepository $userRepo = null,
        ?OrganizationRepository $orgRepo = null,
        ?EntityManagerInterface $em = null,
        ?UrlGeneratorInterface $urlGenerator = null
    ): SSOAuthenticator {
        return new SSOAuthenticator(
            $urlGenerator ?? $this->createUrlGenerator(),
            $em ?? $this->createMock(EntityManagerInterface::class),
            $userRepo ?? $this->createMock(UserRepository::class),
            $orgRepo ?? $this->createMock(OrganizationRepository::class)
        );
    }

    private function createUrlGenerator(): UrlGeneratorInterface
    {
        $ug = $this->createMock(UrlGeneratorInterface::class);
        $ug->method('generate')->willReturnCallback(fn ($route) => '/' . $route);
        return $ug;
    }

    private function buildPostRequest(array $params): Request
    {
        $request = Request::create('/sso', 'POST');
        $request->request->replace($params);
        return $request;
    }

    public function testSupportsReturnsTrueForPostWithSsoToken(): void
    {
        $request = $this->buildPostRequest(['sso_token' => 'abc']);
        self::assertTrue($this->createAuthenticator()->supports($request));
    }

    public function testSupportsReturnsFalseForPostWithoutSsoToken(): void
    {
        $request = Request::create('/sso', 'POST');
        self::assertFalse($this->createAuthenticator()->supports($request));
    }

    public function testSupportsReturnsFalseForGetWithSsoToken(): void
    {
        $request = Request::create('/sso?token=x', 'GET');
        $request->request->set('sso_token', 'abc');
        self::assertFalse($this->createAuthenticator()->supports($request));
    }

    public function testAuthenticateReturnsPassportWithUserBadge(): void
    {
        $request = $this->buildPostRequest(['sso_token' => 'tok', 'sso_provider' => 'google']);
        $passport = $this->createAuthenticator()->authenticate($request);
        self::assertInstanceOf(Passport::class, $passport);
        $badge = $passport->getBadge(UserBadge::class);
        self::assertInstanceOf(UserBadge::class, $badge);
    }

    public function testValidateAndLoadUserExistingUserBySsoId(): void
    {
        $existing = new User();
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findOneBy')->willReturn($existing);

        $request = $this->buildPostRequest(['sso_token' => 'invalid', 'sso_provider' => 'google']);
        $passport = $this->createAuthenticator($userRepo)->authenticate($request);
        $badge = $passport->getBadge(UserBadge::class);
        self::assertSame($existing, $badge->getUser());
    }

    public function testValidateAndLoadUserReturnsNullForInvalidTokenAndNoExistingUser(): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findOneBy')->willReturn(null);

        $request = $this->buildPostRequest(['sso_token' => 'totally-invalid-token', 'sso_provider' => 'google']);
        $passport = $this->createAuthenticator($userRepo)->authenticate($request);
        $badge = $passport->getBadge(UserBadge::class);
        $this->expectException(\Symfony\Component\Security\Core\Exception\UserNotFoundException::class);
        $badge->getUser();
    }

    public function testValidateAndLoadUserCreatesNewUserFromJwtToken(): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findOneBy')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist');
        $em->expects(self::once())->method('flush');

        // Build a fake JWT: header.payload.signature
        $payload = ['email' => 'new@example.com', 'first_name' => 'Max', 'last_name' => 'Müller'];
        $jwt = 'header.' . base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)) . '.sig';

        $request = $this->buildPostRequest(['sso_token' => $jwt, 'sso_provider' => 'google']);
        $passport = $this->createAuthenticator($userRepo, null, $em)->authenticate($request);
        $badge = $passport->getBadge(UserBadge::class);
        $user = $badge->getUser();
        self::assertInstanceOf(User::class, $user);
        self::assertSame('new@example.com', $user->getEmail());
        self::assertSame('Max', $user->getFirstName());
        self::assertSame('Müller', $user->getLastName());
        self::assertSame('google', $user->getSsoProvider());
        self::assertContains('ROLE_USER', $user->getRoles());
    }

    public function testValidateAndLoadUserUpdatesExistingUserByEmail(): void
    {
        $userByEmail = new User();
        $userByEmail->setEmail('existing@example.com');

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findOneBy')->willReturnCallback(function (array $criteria) use ($userByEmail) {
            if (isset($criteria['email'])) {
                return $userByEmail;
            }
            return null;
        });

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::atLeastOnce())->method('persist');
        $em->expects(self::atLeastOnce())->method('flush');

        $payload = ['email' => 'existing@example.com'];
        $jwt = 'h.' . base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)) . '.s';

        $request = $this->buildPostRequest(['sso_token' => $jwt, 'sso_provider' => 'ms']);
        $passport = $this->createAuthenticator($userRepo, null, $em)->authenticate($request);
        $user = $passport->getBadge(UserBadge::class)->getUser();
        self::assertSame($userByEmail, $user);
        self::assertSame('ms', $userByEmail->getSsoProvider());
    }

    public function testValidateAndLoadUserNewUserWithFallbackEmail(): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findOneBy')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist');

        // Plain JSON token without email -> fallback email uses token as identifier
        $jsonToken = json_encode(['first_name' => 'Test'], JSON_THROW_ON_ERROR);

        $request = $this->buildPostRequest(['sso_token' => $jsonToken, 'sso_provider' => 'custom']);
        $passport = $this->createAuthenticator($userRepo, null, $em)->authenticate($request);
        $user = $passport->getBadge(UserBadge::class)->getUser();
        self::assertInstanceOf(User::class, $user);
        // userIdentifier is the token itself, fallback email = token@provider
        self::assertSame($jsonToken . '@custom', $user->getEmail());
    }

    public function testValidateAndLoadUserAssignsOrganizationByDomainMatch(): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findOneBy')->willReturn(null);

        $org = new Organization();
        $orgRef = new \ReflectionProperty(Organization::class, 'id');
        $orgRef->setAccessible(true);
        $orgRef->setValue($org, 7);
        $org->setSettings(['allowed_domains' => ['example.com']]);
        $org->setIsActive(true);

        $orgRepo = $this->createMock(OrganizationRepository::class);
        $orgRepo->method('findAll')->willReturn([$org]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist');

        $payload = ['email' => 'user@example.com'];
        $jwt = 'h.' . base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)) . '.s';

        $request = $this->buildPostRequest(['sso_token' => $jwt, 'sso_provider' => 'google']);
        $passport = $this->createAuthenticator($userRepo, $orgRepo, $em)->authenticate($request);
        $user = $passport->getBadge(UserBadge::class)->getUser();
        self::assertEquals(7, $user->getOrganizationId());
    }

    public function testValidateAndLoadUserAssignsFirstActiveOrganizationWhenNoDomainMatch(): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findOneBy')->willReturn(null);

        $org = new Organization();
        $orgRef = new \ReflectionProperty(Organization::class, 'id');
        $orgRef->setAccessible(true);
        $orgRef->setValue($org, 9);
        $org->setSettings(['allowed_domains' => ['other.com']]);
        $org->setIsActive(true);

        $orgRepo = $this->createMock(OrganizationRepository::class);
        $orgRepo->method('findAll')->willReturn([$org]);

        $em = $this->createMock(EntityManagerInterface::class);

        $payload = ['email' => 'user@example.com'];
        $jwt = 'h.' . base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)) . '.s';

        $request = $this->buildPostRequest(['sso_token' => $jwt, 'sso_provider' => 'google']);
        $passport = $this->createAuthenticator($userRepo, $orgRepo, $em)->authenticate($request);
        $user = $passport->getBadge(UserBadge::class)->getUser();
        self::assertEquals(9, $user->getOrganizationId());
    }

    public function testValidateAndLoadUserNoOrganizationWhenNoneActive(): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findOneBy')->willReturn(null);

        $org = new Organization();
        $org->setSettings(['allowed_domains' => ['other.com']]);
        $org->setIsActive(false);

        $orgRepo = $this->createMock(OrganizationRepository::class);
        $orgRepo->method('findAll')->willReturn([$org]);

        $em = $this->createMock(EntityManagerInterface::class);

        $payload = ['email' => 'user@example.com'];
        $jwt = 'h.' . base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)) . '.s';

        $request = $this->buildPostRequest(['sso_token' => $jwt, 'sso_provider' => 'google']);
        $passport = $this->createAuthenticator($userRepo, $orgRepo, $em)->authenticate($request);
        $user = $passport->getBadge(UserBadge::class)->getUser();
        self::assertNull($user->getOrganizationId());
    }

    public function testValidateAndLoadUserInvalidDomainReturnsNullOrganization(): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findOneBy')->willReturn(null);

        $orgRepo = $this->createMock(OrganizationRepository::class);
        $orgRepo->method('findAll')->willReturn([]);

        $em = $this->createMock(EntityManagerInterface::class);

        $payload = ['email' => 'not-an-email'];
        $jwt = 'h.' . base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)) . '.s';

        $request = $this->buildPostRequest(['sso_token' => $jwt, 'sso_provider' => 'google']);
        $passport = $this->createAuthenticator($userRepo, $orgRepo, $em)->authenticate($request);
        $user = $passport->getBadge(UserBadge::class)->getUser();
        self::assertInstanceOf(User::class, $user);
        self::assertNull($user->getOrganizationId());
    }

    public function testDecodeTokenWithInvalidJwtStructureFallsBackToJson(): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findOneBy')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);

        // Token that is valid JSON but not a 3-part JWT
        $jsonToken = json_encode(['email' => 'json@example.com'], JSON_THROW_ON_ERROR);

        $request = $this->buildPostRequest(['sso_token' => $jsonToken, 'sso_provider' => 'json']);
        $passport = $this->createAuthenticator($userRepo, null, $em)->authenticate($request);
        $user = $passport->getBadge(UserBadge::class)->getUser();
        self::assertSame('json@example.com', $user->getEmail());
    }

    public function testOnAuthenticationSuccessUpdatesLastLoginAndRedirectsHome(): void
    {
        $user = new User();
        // Mark onboarding complete so we go to app_home
        $ref = new \ReflectionProperty(User::class, 'onboardingComplete');
        $ref->setAccessible(true);
        $ref->setValue($user, true);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist');
        $em->expects(self::once())->method('flush');

        $request = $this->buildPostRequest([]);
        $response = $this->createAuthenticator(null, null, $em)->onAuthenticationSuccess($request, $token, 'main');
        self::assertSame('/app_home', $response->getTargetUrl());
    }

    public function testOnAuthenticationSuccessRedirectsToOnboardingIfIncomplete(): void
    {
        $user = new User();
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist');
        $em->expects(self::once())->method('flush');

        $request = $this->buildPostRequest([]);
        $response = $this->createAuthenticator(null, null, $em)->onAuthenticationSuccess($request, $token, 'main');
        self::assertSame('/app_onboarding', $response->getTargetUrl());
    }

    public function testOnAuthenticationSuccessRedirectsToTargetPath(): void
    {
        $user = new User();
        $ref = new \ReflectionProperty(User::class, 'onboardingComplete');
        $ref->setAccessible(true);
        $ref->setValue($user, true);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $em = $this->createMock(EntityManagerInterface::class);

        $request = $this->buildPostRequest(['_target_path' => '/custom-target']);
        $response = $this->createAuthenticator(null, null, $em)->onAuthenticationSuccess($request, $token, 'main');
        self::assertSame('/custom-target', $response->getTargetUrl());
    }

    public function testOnAuthenticationSuccessWithNonUserUser(): void
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn(null);

        $request = $this->buildPostRequest([]);
        $response = $this->createAuthenticator()->onAuthenticationSuccess($request, $token, 'main');
        self::assertSame('/app_home', $response->getTargetUrl());
    }

    public function testOnAuthenticationSuccessWithEmptyTargetPath(): void
    {
        $user = new User();
        $ref = new \ReflectionProperty(User::class, 'onboardingComplete');
        $ref->setAccessible(true);
        $ref->setValue($user, true);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $em = $this->createMock(EntityManagerInterface::class);

        $request = $this->buildPostRequest(['_target_path' => '']);
        $response = $this->createAuthenticator(null, null, $em)->onAuthenticationSuccess($request, $token, 'main');
        self::assertSame('/app_home', $response->getTargetUrl());
    }

    public function testOnAuthenticationFailureAddsFlashAndRedirects(): void
    {
        $exception = new AuthenticationException('Bad credentials.');
        $session = new Session(new MockFileSessionStorage());
        $request = Request::create('/sso', 'POST');
        $request->setSession($session);

        $response = $this->createAuthenticator()->onAuthenticationFailure($request, $exception);
        self::assertSame('/app_login', $response->getTargetUrl());
        self::assertTrue($session->getFlashBag()->has('error'));
    }

    public function testOnAuthenticationFailureWithoutSessionThrows(): void
    {
        $exception = new AuthenticationException('Bad.');
        $request = Request::create('/sso', 'POST');
        // No session set -> Symfony throws SessionNotFoundException
        $this->expectException(\Symfony\Component\HttpFoundation\Exception\SessionNotFoundException::class);
        $this->createAuthenticator()->onAuthenticationFailure($request, $exception);
    }

    public function testStartRedirectsToLogin(): void
    {
        $request = Request::create('/protected');
        $response = $this->createAuthenticator()->start($request);
        self::assertSame('/app_login', $response->getTargetUrl());
    }

    public function testStartWithNullException(): void
    {
        $request = Request::create('/protected');
        $response = $this->createAuthenticator()->start($request, null);
        self::assertSame('/app_login', $response->getTargetUrl());
    }

    public function testAuthenticateWithDefaultProvider(): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findOneBy')->willReturnCallback(function (array $criteria) {
            if (isset($criteria['ssoProvider']) && $criteria['ssoProvider'] === 'custom') {
                return new User();
            }
            return null;
        });

        $request = $this->buildPostRequest(['sso_token' => 'tok']);
        $passport = $this->createAuthenticator($userRepo)->authenticate($request);
        self::assertInstanceOf(Passport::class, $passport);
    }
}
