<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * E2E-Tests für die Authentifizierungs-Flows von EVIE.
 *
 * Getestet werden die grundlegenden Funktionen:
 *  - Registrierung
 *  - Login
 *  - Logout
 *  - Navigation zwischen geschützten Bereichen
 *  - Profil / Passwort ändern
 *  - Passwort vergessen (Reset-Token-Flow)
 *
 * Verwendet den Symfony Test-Client (Kernel-HTTP-Client) als stabile,
 * browserunabhängige Alternative zu Panther — kein Chrome/Chromium nötig.
 */
class AuthFlowTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private UserPasswordHasherInterface $passwordHasher;

    protected function setUp(): void
    {
        parent::setUp();
        // WICHTIG: createClient() MUSS vor static::getContainer() aufgerufen werden,
        // da getContainer() den Kernel bootet und createClient() sonst fehlschlägt.
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->passwordHasher = $container->get(UserPasswordHasherInterface::class);

        // Schema pro Test sicherstellen (erlaubt In-Memory-SQLite in jedem Env).
        $this->ensureSchema();

        // Datenbank für jeden Test zurücksetzen
        $this->purgeUsers();
    }

    protected function tearDown(): void
    {
        $this->purgeUsers();
        parent::tearDown();
    }

    public function testRegisterCreatesUserAndRedirectsToLogin(): void
    {
        $crawler = $this->client->request('GET', '/register');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form');
        $this->assertSelectorTextContains('h2', 'Konto erstellen');

        $csrfToken = $this->extractCsrfToken($crawler, 'registration');

        $this->client->submitForm('Registrieren', [
            'registration_form[firstName]' => 'Max',
            'registration_form[lastName]' => 'Mustermann',
            'registration_form[email]' => 'max@beispiel.de',
            'registration_form[plainPassword]' => 'SicheresPasswort123',
            'registration_form[agreeTerms]' => 1,
            'registration_form[_csrf_token]' => $csrfToken,
        ]);

        $this->assertResponseRedirects('/login');

        $user = $this->getRepository()->findOneBy(['email' => 'max@beispiel.de']);
        $this->assertNotNull($user, 'Benutzer sollte nach Registrierung existieren');
        $this->assertSame('Max', $user->getFirstName());
        $this->assertSame('Mustermann', $user->getLastName());
        $this->assertTrue($user->isActive());
        $this->assertNotEmpty($user->getPassword(), 'Passwort sollte gehasht gespeichert sein');
        $this->assertNotSame('SicheresPasswort123', $user->getPassword(), 'Passwort darf nicht im Klartext gespeichert werden');
    }

    public function testRegisterRejectsDuplicateEmail(): void
    {
        $this->createUser('duplikat@beispiel.de', 'VorhandenesPasswort123');

        $crawler = $this->client->request('GET', '/register');
        $csrfToken = $this->extractCsrfToken($crawler, 'registration');

        $this->client->submitForm('Registrieren', [
            'registration_form[firstName]' => 'Zweiter',
            'registration_form[lastName]' => 'Versuch',
            'registration_form[email]' => 'duplikat@beispiel.de',
            'registration_form[plainPassword]' => 'NochEinPasswort456',
            'registration_form[agreeTerms]' => 1,
            'registration_form[_csrf_token]' => $csrfToken,
        ]);

        // Bei Form-Validierungsfehler liefert Symfony 422 (Unprocessable) oder 200,
        // aber keinen Redirect.
        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertTrue(
            in_array($statusCode, [200, 422], true),
            sprintf('Duplikat-Registrierung sollte nicht weiterleiten, bekam %d.', $statusCode)
        );
        $this->assertSelectorTextContains('', 'bereits registriert');
    }

    public function testLoginWithValidCredentials(): void
    {
        $this->createUser('login@beispiel.de', 'MeinPasswort123');

        $crawler = $this->client->request('GET', '/login');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h2', 'Willkommen zurück');

        $csrfToken = $this->extractCsrfToken($crawler, 'authenticate');

        $this->client->request('POST', '/login', [
            'email' => 'login@beispiel.de',
            'password' => 'MeinPasswort123',
            '_csrf_token' => $csrfToken,
            '_remember_me' => 1,
            '_target_path' => '/',
        ]);

        $this->assertResponseRedirects('/');
        $this->client->followRedirect();

        // Nach Login sollte der Benutzer authentifiziert sein
        $this->assertNotNull($this->getUser(), 'Benutzer sollte nach Login authentifiziert sein');
    }

    public function testLoginWithInvalidCredentialsFails(): void
    {
        $this->createUser('gut@beispiel.de', 'RichtigesPasswort123');

        $crawler = $this->client->request('GET', '/login');
        $csrfToken = $this->extractCsrfToken($crawler, 'authenticate');

        $this->client->request('POST', '/login', [
            'email' => 'gut@beispiel.de',
            'password' => 'FalschesPasswort',
            '_csrf_token' => $csrfToken,
        ]);

        // Bei Fehlschlag Redirect zurück zum Login
        $this->assertResponseRedirects('/login');
        $this->client->followRedirect();
        $this->assertNull(static::getContainer()->get('security.token_storage')->getToken()?->getUser());
    }

    public function testProtectedRouteRedirectsAnonymousUserToLogin(): void
    {
        // Im Test-Client liefert der Entry-Point ohne Browser-Accept-Header einen
        // 401 für anonyme Zugriffe auf geschützte Routen.
        $this->client->request('GET', '/');
        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertTrue(
            in_array($statusCode, [302, 401], true),
            sprintf('Anonymer Zugriff auf geschützte Route sollte zu Login weiterleiten (302) oder 401 liefern, bekam %d.', $statusCode)
        );
    }

    public function testLogoutClearsSession(): void
    {
        $this->createUserAndLogin('logout@beispiel.de', 'LogoutPasswort123');
        $this->assertNotNull($this->getUser());

        $this->client->request('GET', '/logout');

        // Logout leitet weiter
        $this->assertResponseRedirects();
        $this->client->followRedirect();

        $token = static::getContainer()->get('security.token_storage')->getToken();
        $this->assertNull($token?->getUser(), 'Benutzer sollte nach Logout abgemeldet sein');
    }

    public function testNavigationToProfileWhenLoggedIn(): void
    {
        $this->createUserAndLogin('nav@beispiel.de', 'NavPasswort123');

        $this->client->request('GET', '/profile');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1.card-title', 'Mein Profil');
        $this->assertSelectorTextContains('', 'nav@beispiel.de');
    }

    public function testChangePasswordUpdatesHash(): void
    {
        $user = $this->createUserAndLogin('aendern@beispiel.de', 'AltesPasswort123');
        $oldHash = $user->getPassword();

        $crawler = $this->client->request('GET', '/profile');
        $csrfToken = $this->extractCsrfToken($crawler, 'change_password');

        $this->client->submitForm('Passwort ändern', [
            'change_password_form[currentPassword]' => 'AltesPasswort123',
            'change_password_form[plainPassword]' => 'NeuesPasswort456',
            'change_password_form[_csrf_token]' => $csrfToken,
        ]);

        $this->assertResponseRedirects('/profile');
        $this->client->followRedirect();

        // Neue Hash aus der DB lesen
        $this->entityManager->clear();
        $refreshed = $this->getRepository()->find($user->getId());
        $this->assertNotNull($refreshed);
        $this->assertNotSame($oldHash, $refreshed->getPassword(), 'Passwort-Hash sollte sich geändert haben');
        $this->assertTrue(
            $this->passwordHasher->isPasswordValid($refreshed, 'NeuesPasswort456'),
            'Neues Passwort sollte gültig sein'
        );
    }

    public function testChangePasswordRejectsWrongCurrentPassword(): void
    {
        $this->createUserAndLogin('wp@beispiel.de', 'RichtigesPasswort123');

        $crawler = $this->client->request('GET', '/profile');
        $csrfToken = $this->extractCsrfToken($crawler, 'change_password');

        $this->client->submitForm('Passwort ändern', [
            'change_password_form[currentPassword]' => 'FalschesAltes',
            'change_password_form[plainPassword]' => 'NeuesPasswort456',
            'change_password_form[_csrf_token]' => $csrfToken,
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertTrue(
            in_array($statusCode, [200, 422], true),
            sprintf('Passwort-Änderung mit falschem Passwort sollte nicht weiterleiten, bekam %d.', $statusCode)
        );
        $this->assertSelectorTextContains('', 'aktuelle Passwort ist nicht korrekt');
    }

    public function testForgotPasswordRequestShowsGenericMessage(): void
    {
        $this->createUser('reset@beispiel.de', 'ResetPasswort123');

        $crawler = $this->client->request('GET', '/forgot-password');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h2', 'Passwort vergessen');

        $csrfToken = $this->extractCsrfToken($crawler, 'forgot_password');

        $this->client->submitForm('Reset-Link anfordern', [
            'forgot_password_form[email]' => 'reset@beispiel.de',
            'forgot_password_form[_csrf_token]' => $csrfToken,
        ]);

        $this->assertResponseRedirects('/forgot-password');
        $this->client->followRedirect();

        // Datenschutzfreundliche Meldung (auch bei unbekannter E-Mail gleich)
        $this->assertSelectorTextContains('', 'Reset-Link versendet');
    }

    public function testForgotPasswordForUnknownEmailShowsSameMessage(): void
    {
        $crawler = $this->client->request('GET', '/forgot-password');
        $csrfToken = $this->extractCsrfToken($crawler, 'forgot_password');

        $this->client->submitForm('Reset-Link anfordern', [
            'forgot_password_form[email]' => 'existiert-nicht@beispiel.de',
            'forgot_password_form[_csrf_token]' => $csrfToken,
        ]);

        $this->assertResponseRedirects('/forgot-password');
        $this->client->followRedirect();
        $this->assertSelectorTextContains('', 'Reset-Link versendet');
    }

    public function testResetPasswordWithValidTokenChangesPassword(): void
    {
        $user = $this->createUser('valid-reset@beispiel.de', 'AltesResetPasswort123');
        $oldHash = $user->getPassword();

        $token = $this->generateResetToken($user);

        $crawler = $this->client->request('GET', '/reset-password/' . $token);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h2', 'Neues Passwort festlegen');

        $csrfToken = $this->extractCsrfToken($crawler, 'reset_password');

        $this->client->submitForm('Passwort ändern', [
            'reset_password_form[plainPassword]' => 'GanzNeuesPasswort789',
            'reset_password_form[_csrf_token]' => $csrfToken,
        ]);

        $this->assertResponseRedirects('/login');

        $this->entityManager->clear();
        $refreshed = $this->getRepository()->find($user->getId());
        $this->assertNotSame($oldHash, $refreshed->getPassword());
        $this->assertTrue(
            $this->passwordHasher->isPasswordValid($refreshed, 'GanzNeuesPasswort789')
        );
    }

    public function testResetPasswordWithInvalidTokenRedirectsToForgot(): void
    {
        $this->client->request('GET', '/reset-password/invalid-token-string');

        $this->assertResponseRedirects('/forgot-password');
        $this->client->followRedirect();
        $this->assertSelectorTextContains('', 'ungültig oder abgelaufen');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function getRepository(): UserRepository
    {
        return $this->entityManager->getRepository(User::class);
    }

    private function ensureSchema(): void
    {
        $schemaTool = new SchemaTool($this->entityManager);
        $classes = $this->entityManager->getMetadataFactory()->getAllMetadata();
        try {
            $schemaTool->createSchema($classes);
        } catch (\Throwable) {
            // Schema existiert bereits (dateibasierte DB) -> ignorieren.
        }
    }

    private function purgeUsers(): void
    {
        $conn = $this->entityManager->getConnection();
        // Reihenfolge wegen FK beachten
        $conn->executeStatement('DELETE FROM reset_password_request');
        $conn->executeStatement('DELETE FROM users');
        $this->entityManager->clear();
    }

    private function createUser(string $email, string $plainPassword): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setFirstName('Test')
            ->setLastName('User')
            ->setPassword($this->passwordHasher->hashPassword(new User(), $plainPassword));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function createUserAndLogin(string $email, string $plainPassword): User
    {
        $user = $this->createUser($email, $plainPassword);

        $crawler = $this->client->request('GET', '/login');
        $csrfToken = $this->extractCsrfToken($crawler, 'authenticate');

        $this->client->request('POST', '/login', [
            'email' => $email,
            'password' => $plainPassword,
            '_csrf_token' => $csrfToken,
            '_remember_me' => 1,
            '_target_path' => '/',
        ]);
        $this->client->followRedirect();

        return $user;
    }

    private function generateResetToken(User $user): string
    {
        $generator = static::getContainer()->get(\App\Security\ResetPasswordTokenGenerator::class);

        return $generator->generate($user);
    }

    /**
     * Extrahiert ein CSRF-Token aus dem gerenderten Formular-Crawler.
     *
     * Symfony benennt das CSRF-Feld nach dem Formularnamen (z. B.
     * "forgot_password_form[_csrf_token]"), daher wird nach jedem Input
     * gesucht, dessen Name auf "_csrf_token" endet.
     */
    private function extractCsrfToken(Crawler $crawler, string $tokenId): string
    {
        // Suche nach jedem versteckten Input, dessen id auf "_csrf_token" endet.
        $tokenInput = $crawler->filter('input[type="hidden"][id$="_csrf_token"]')->last();

        if ($tokenInput->count() > 0) {
            $value = $tokenInput->attr('value');
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        // Breiter Fallback: alle versteckten Inputs durchsuchen.
        foreach ($crawler->filter('input[type="hidden"]') as $node) {
            $name = $node->getAttribute('name') ?? '';
            if (str_ends_with($name, '[_csrf_token]') || $name === '_csrf_token') {
                $value = $node->getAttribute('value');
                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }

        // Letzter Fallback: Token über das CSRF-Manager-Interface erzeugen.
        // (Erfordert eine aktive Sitzung — sollte in der Regel nicht erreicht werden.)
        return static::getContainer()
            ->get('security.csrf.token_manager')
            ->getToken($tokenId)
            ->getValue();
    }

    private function getUser(): ?User
    {
        $token = static::getContainer()->get('security.token_storage')->getToken();

        $user = $token?->getUser();
        return $user instanceof User ? $user : null;
    }
}
