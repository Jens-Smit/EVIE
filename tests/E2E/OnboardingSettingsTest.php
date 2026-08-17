<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * E2E-Tests fuer Onboarding-Flow (F4) und Einstellungen-Seite (F3).
 *
 * Deckt ab:
 *  - Neuer Nutzer wird nach Login zum Onboarding weitergeleitet (onboardingComplete=false)
 *  - Onboarding-Seite /onboarding ist erreichbar und zeigt Willkommen
 *  - Onboarding /complete-Endpunkt setzt onboardingComplete=true
 *  - Einstellungen-Seite /settings ist erreichbar (vorher href="#")
 *  - Profil-Update funktioniert
 *  - Onboarding-Reset funktioniert
 */
class OnboardingSettingsTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private UserPasswordHasherInterface $passwordHasher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->passwordHasher = $container->get(UserPasswordHasherInterface::class);
        $this->ensureSchema();
        $this->purgeUsers();
    }

    protected function tearDown(): void
    {
        $this->purgeUsers();
        parent::tearDown();
    }

    public function testNewUserIsRedirectedToOnboardingAfterLogin(): void
    {
        $user = $this->createUser('onboard@beispiel.de', 'OnboardPass123');
        // Neuer User hat onboardingComplete=false (Default).
        $this->assertFalse($user->isOnboardingComplete());

        $this->login('onboard@beispiel.de', 'OnboardPass123');

        // Nach Login sollte der Redirect auf /onboarding zeigen.
        $this->assertResponseRedirects('/onboarding');
    }

    public function testUserWithCompleteOnboardingGoesToHome(): void
    {
        $user = $this->createUser('complete@beispiel.de', 'CompletePass123');
        $user->setOnboardingComplete(true);
        $this->entityManager->flush();

        $this->login('complete@beispiel.de', 'CompletePass123');

        // Onboarding bereits abgeschlossen -> Redirect auf / (Home).
        $this->assertResponseRedirects('/');
    }

    public function testOnboardingPageLoads(): void
    {
        $this->createUserAndLogin('onboardpage@beispiel.de', 'OnboardPagePass123');

        $this->client->request('GET', '/onboarding');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[x-data="onboardingFlow()"]');
        $this->assertSelectorTextContains('', 'Willkommen bei EVIE');
        $this->assertSelectorTextContains('', 'Lass uns ein paar Dinge einrichten');
    }

    public function testOnboardingPageRedirectsToDashboardIfComplete(): void
    {
        $user = $this->createUser('completed@beispiel.de', 'CompletedPass123');
        $user->setOnboardingComplete(true);
        $this->entityManager->flush();
        $this->login('completed@beispiel.de', 'CompletedPass123');

        $this->client->request('GET', '/onboarding');

        $this->assertResponseRedirects('/dashboard');
    }

    public function testOnboardingCompleteEndpointSetsFlag(): void
    {
        $this->createUserAndLogin('completeep@beispiel.de', 'CompleteEpPass123');

        $this->client->request('POST', '/onboarding/complete', [], [], [
            'HTTP_X-Requested-With' => 'XMLHttpRequest',
        ]);

        $this->assertResponseIsSuccessful();
        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('completed', $response['status']);

        // Flag in der DB pruefen.
        $user = $this->entityManager->getRepository(User::class)
            ->findOneBy(['email' => 'completeep@beispiel.de']);
        $this->assertTrue($user->isOnboardingComplete());
    }

    public function testSettingsPageLoads(): void
    {
        $this->createUserAndLogin('settings@beispiel.de', 'SettingsPass123');

        $this->client->request('GET', '/settings');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('', 'Einstellungen');
        $this->assertSelectorTextContains('h2', 'Profil');
    }

    public function testSettingsPageShowsOnboardingStatus(): void
    {
        $this->createUserAndLogin('status@beispiel.de', 'StatusPass123');

        $this->client->request('GET', '/settings');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('', 'Onboarding');
        $this->assertSelectorTextContains('', 'Ausstehend');
    }

    public function testProfileUpdateWorks(): void
    {
        $this->createUserAndLogin('profileupd@beispiel.de', 'ProfileUpdPass123');

        $this->client->request('POST', '/settings/profile', [
            'firstName' => 'NeuerVorname',
            'lastName' => 'NeuerNachname',
        ]);

        $this->assertResponseRedirects('/settings');
        $this->client->followRedirect();
        $this->assertSelectorTextContains('', 'Profil wurde aktualisiert');

        $user = $this->entityManager->getRepository(User::class)
            ->findOneBy(['email' => 'profileupd@beispiel.de']);
        $this->assertSame('NeuerVorname', $user->getFirstName());
        $this->assertSame('NeuerNachname', $user->getLastName());
    }

    public function testOnboardingResetRedirectsToOnboarding(): void
    {
        $user = $this->createUser('reset@beispiel.de', 'ResetPass123');
        $user->setOnboardingComplete(true);
        $this->entityManager->flush();
        $this->login('reset@beispiel.de', 'ResetPass123');

        $this->client->request('POST', '/settings/onboarding/reset');

        $this->assertResponseRedirects('/onboarding');
    }

    public function testAnonymousAccessToOnboardingRedirectsToLogin(): void
    {
        $this->client->request('GET', '/onboarding');
        $this->assertResponseRedirects('/login');
    }

    public function testAnonymousAccessToSettingsRedirectsToLogin(): void
    {
        $this->client->request('GET', '/settings');
        $this->assertResponseRedirects('/login');
    }

    public function testSidebarShowsSettingsLink(): void
    {
        $this->createUserAndLogin('sidebar@beispiel.de', 'SidebarPass123');
        $crawler = $this->client->request('GET', '/dashboard');
        $settingsLink = $crawler->filter('a[href*="/settings"]');
        $this->assertGreaterThan(0, $settingsLink->count(), 'Sidebar sollte einen /settings-Link enthalten.');
        $this->assertSame('Einstellungen', trim($settingsLink->text()));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function ensureSchema(): void
    {
        $schemaTool = new SchemaTool($this->entityManager);
        $classes = $this->entityManager->getMetadataFactory()->getAllMetadata();
        try {
            $schemaTool->createSchema($classes);
        } catch (\Throwable) {
            // Schema existiert bereits -> ignorieren.
        }
    }

    private function purgeUsers(): void
    {
        $conn = $this->entityManager->getConnection();
        $conn->executeStatement('DELETE FROM reset_password_request');
        $conn->executeStatement('DELETE FROM tool_definitions');
        $conn->executeStatement('DELETE FROM ai_sub_agent_definitions');
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
        $this->login($email, $plainPassword);
        // Onboarding-Redirect überspringen, indem wir es direkt als complete markieren.
        $user->setOnboardingComplete(true);
        $this->entityManager->flush();
        return $user;
    }

    private function login(string $email, string $plainPassword): void
    {
        $crawler = $this->client->request('GET', '/login');
        $csrfToken = $this->extractCsrfToken($crawler);
        $this->client->request('POST', '/login', [
            'email' => $email,
            'password' => $plainPassword,
            '_csrf_token' => $csrfToken,
            '_remember_me' => 1,
        ]);
    }

    private function extractCsrfToken(Crawler $crawler): string
    {
        $tokenInput = $crawler->filter('input[type="hidden"][id$="_csrf_token"]')->last();
        if ($tokenInput->count() > 0) {
            $value = $tokenInput->attr('value');
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }
        foreach ($crawler->filter('input[type="hidden"]') as $node) {
            $name = $node->getAttribute('name') ?? '';
            if (str_ends_with($name, '[_csrf_token]') || $name === '_csrf_token') {
                $value = $node->getAttribute('value');
                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }
        return static::getContainer()
            ->get('security.csrf.token_manager')
            ->getToken('authenticate')
            ->getValue();
    }
}
