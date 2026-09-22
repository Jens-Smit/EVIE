<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

/**
 * Functional-Tests fuer SecretController (Frontend-Settings).
 *
 * SecretController war laut Coverage-Report ungetestet (0%). Deckt
 * index/create (Validierung + Erfolg)/delete/check ab.
 */
class SecretControllerTest extends AbstractFunctionalControllerTestCase
{
    public function testSecretsPageRequiresAuthentication(): void
    {
        $this->client->request('GET', '/settings/secrets');

        self::assertResponseRedirects('/login');
    }

    public function testSecretsPageRendersForUser(): void
    {
        $this->createUserAndLogin('secrets-page@test.de', 'SecretPass123');
        $this->client->request('GET', '/settings/secrets');

        self::assertResponseIsSuccessful();
    }

    public function testCreateSecretWithEmptyNameShowsError(): void
    {
        $this->createUserAndLogin('secrets-empty@test.de', 'SecretPass123');
        $this->client->request('POST', '/settings/secrets', [
            'keyName' => '',
            'value' => 'some-value',
        ]);

        self::assertResponseRedirects('/settings/secrets');
        $this->client->followRedirect();
        self::assertSelectorTextContains('', 'Schlüsselname darf nicht leer sein');
    }

    public function testCreateSecretWithEmptyValueShowsError(): void
    {
        $this->createUserAndLogin('secrets-emptyval@test.de', 'SecretPass123');
        $this->client->request('POST', '/settings/secrets', [
            'keyName' => 'VALID_KEY',
            'value' => '',
        ]);

        self::assertResponseRedirects('/settings/secrets');
    }

    public function testCreateSecretWithInvalidNameShowsError(): void
    {
        $this->createUserAndLogin('secrets-invalid@test.de', 'SecretPass123');
        $this->client->request('POST', '/settings/secrets', [
            'keyName' => 'invalid key!',
            'value' => 'some-value',
        ]);

        self::assertResponseRedirects('/settings/secrets');
        $this->client->followRedirect();
        self::assertSelectorTextContains('', 'Buchstaben, Zahlen, Unterstriche');
    }

    public function testCreateAndDeleteSecret(): void
    {
        $this->createUserAndLogin('secrets-crud@test.de', 'SecretPass123');

        $this->client->request('POST', '/settings/secrets', [
            'keyName' => 'TEST_API_KEY',
            'value' => 'secret-value-123',
        ]);
        self::assertResponseRedirects('/settings/secrets');

        $this->client->request('POST', '/settings/secrets/TEST_API_KEY');
        self::assertResponseRedirects('/settings/secrets');
    }

    public function testDeleteUnknownSecretShowsError(): void
    {
        $this->createUserAndLogin('secrets-unknown@test.de', 'SecretPass123');
        $this->client->request('POST', '/settings/secrets/UNKNOWN_KEY');

        self::assertResponseRedirects('/settings/secrets');
    }

    public function testCheckSecretReturnsUnauthorizedWithoutLogin(): void
    {
        $this->client->request('POST', '/api/secrets/check', ['keyName' => 'ANY']);

        self::assertResponseStatusCodeSame(401);
    }

    public function testCheckSecretWithEmptyNameReturns400(): void
    {
        $this->createUserAndLogin('secrets-check@test.de', 'SecretPass123');
        $this->client->request('POST', '/api/secrets/check', ['keyName' => '']);

        self::assertResponseStatusCodeSame(400);
    }

    public function testCheckSecretReturnsExistsFalseForUnknownKey(): void
    {
        $this->createUserAndLogin('secrets-check2@test.de', 'SecretPass123');
        $this->client->request('POST', '/api/secrets/check', ['keyName' => 'NOT_SET']);

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertFalse($data['exists']);
    }

    public function testCheckSecretReturnsExistsTrueAfterCreate(): void
    {
        $this->createUserAndLogin('secrets-check3@test.de', 'SecretPass123');
        $this->client->request('POST', '/settings/secrets', [
            'keyName' => 'CHECK_ME',
            'value' => 'value-123',
        ]);
        $this->client->request('POST', '/api/secrets/check', ['keyName' => 'CHECK_ME']);

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertTrue($data['exists']);
    }
}
