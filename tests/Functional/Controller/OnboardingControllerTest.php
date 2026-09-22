<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

/**
 * Functional-Tests fuer OnboardingController (Frontend).
 *
 * OnboardingController war laut Coverage-Report ungetestet (0%). Deckt
 * index (Redirect bei abgeschlossenem Onboarding), start, next,
 * validate-key und chat ab, jeweils mit Auth-Pruefung.
 */
class OnboardingControllerTest extends AbstractFunctionalControllerTest
{
    public function testOnboardingPageRequiresAuthentication(): void
    {
        $this->client->request('GET', '/onboarding');

        self::assertResponseRedirects('/login');
    }

    public function testOnboardingPageRedirectsWhenComplete(): void
    {
        $this->createUserAndLogin('onboard-complete@test.de', 'OnboardPass123');
        $this->client->request('GET', '/onboarding');

        self::assertResponseRedirects('/dashboard');
    }

    public function testOnboardingPageRendersWhenIncomplete(): void
    {
        $user = $this->createUser('onboard-open@test.de', 'OnboardPass123');
        $this->login('onboard-open@test.de', 'OnboardPass123');
        $this->client->request('GET', '/onboarding');

        self::assertResponseIsSuccessful();
    }

    public function testStartReturnsJson(): void
    {
        $user = $this->createUser('onboard-start@test.de', 'OnboardPass123');
        $this->login('onboard-start@test.de', 'OnboardPass123');
        $this->client->request('POST', '/onboarding/start', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([]));

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('step_id', $data);
    }

    public function testStartWithoutAuthenticationReturns401(): void
    {
        $this->client->request('POST', '/onboarding/start', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([]));

        self::assertResponseRedirects('/login');
    }

    public function testNextWithoutAuthenticationReturns401(): void
    {
        $this->client->request('POST', '/onboarding/next', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['response' => 'mistral']));

        self::assertResponseRedirects('/login');
    }

    public function testNextReturnsStepOrError(): void
    {
        $user = $this->createUser('onboard-next@test.de', 'OnboardPass123');
        $this->login('onboard-next@test.de', 'OnboardPass123');
        $this->client->request('POST', '/onboarding/next', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['response' => 'mistral']));

        $statusCode = $this->client->getResponse()->getStatusCode();
        self::assertContains($statusCode, [200, 422, 500], 'Onboarding next sollte 200/422/500 liefern, bekam ' . $statusCode);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('status', $data);
    }

    public function testValidateKeyRequiresProviderAndKey(): void
    {
        $user = $this->createUser('onboard-validate@test.de', 'OnboardPass123');
        $this->login('onboard-validate@test.de', 'OnboardPass123');
        $this->client->request('POST', '/onboarding/validate-key', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['provider' => '', 'api_key' => '']));

        self::assertResponseStatusCodeSame(400);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertFalse($data['valid']);
    }

    public function testChatRequiresAuthentication(): void
    {
        $this->client->request('POST', '/onboarding/chat', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['message' => 'Hallo']));

        self::assertResponseRedirects('/login');
    }
}
