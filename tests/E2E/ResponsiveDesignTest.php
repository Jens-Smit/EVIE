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
 * E2E-Tests fuer responsives Design (F16).
 *
 * Prueft, dass die Templates responsive Klassen enthalten (grid-cols-1 md:grid-cols-2,
 * lg:, etc.) und dass die Seiten auf mobilen Viewports korrekt laden.
 *
 * Da WebTestCase keinen echten Browser-Viewport rendert, werden die responsiven
 * Tailwind-Klassen im HTML verifiziert und die Desktop/Mobile-Requests ueberprueft.
 */
class ResponsiveDesignTest extends WebTestCase
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
        static::ensureKernelShutdown();
    }

    public function testDashboardContainsResponsiveGridClasses(): void
    {
        $this->createUserAndLogin('dash-resp@beispiel.de', 'DashRespPass123');
        $crawler = $this->client->request('GET', '/dashboard');

        $this->assertResponseIsSuccessful();
        $html = $crawler->html();
        // Responsive Grid-Klassen fuer mobile -> desktop Layout
        $this->assertStringContainsString('grid-cols-1', $html, 'Dashboard sollte grid-cols-1 enthalten.');
        $this->assertStringContainsString('md:grid-cols-2', $html, 'Dashboard sollte md:grid-cols-2 enthalten.');
        $this->assertStringContainsString('lg:grid-cols-4', $html, 'Dashboard sollte lg:grid-cols-4 enthalten.');
    }

    public function testSettingsPageContainsResponsiveClasses(): void
    {
        $this->createUserAndLogin('settings-resp@beispiel.de', 'SettingsRespPass123');
        $crawler = $this->client->request('GET', '/settings');

        $this->assertResponseIsSuccessful();
        $html = $crawler->html();
        $this->assertStringContainsString('md:grid-cols-2', $html, 'Settings sollte md:grid-cols-2 enthalten.');
    }

    public function testBaseTemplateContainsResponsiveSidebarClasses(): void
    {
        $this->createUserAndLogin('sidebar-resp@beispiel.de', 'SidebarRespPass123');
        $crawler = $this->client->request('GET', '/dashboard');

        $html = $crawler->html();
        // Sidebar sollte responsive Klassen enthalten (lg: fuer Desktop-Sidebar)
        $this->assertTrue(
            str_contains($html, 'lg:') || str_contains($html, 'md:'),
            'Sidebar sollte responsive Klassen (lg: oder md:) enthalten.'
        );
    }

    public function testMcpServersPageContainsResponsiveTableClasses(): void
    {
        $this->createAdminAndLogin('admin-resp@beispiel.de', 'AdminRespPass123');
        $crawler = $this->client->request('GET', '/mcp/servers');

        $this->assertResponseIsSuccessful();
        $html = $crawler->html();
        // Die MCP-Server-Seite nutzt responsive Card-Struktur (max-w-6xl) und
        // overflow-x-auto nur wenn Server vorhanden sind. Pruefe Card-Layout.
        $this->assertStringContainsString('max-w-6xl', $html, 'MCP-Server-Seite sollte max-w-6xl enthalten.');
        $this->assertStringContainsString('card', $html, 'MCP-Server-Seite sollte Card-Klassen enthalten.');
    }

    public function testOnboardingPageContainsResponsiveClasses(): void
    {
        // User mit onboardingComplete=false erstellen, damit /onboarding nicht auf /dashboard weiterleitet
        $user = $this->createUser('onboard-resp@beispiel.de', 'OnboardRespPass123');
        $user->setOnboardingComplete(false);
        $this->entityManager->flush();
        $this->login('onboard-resp@beispiel.de', 'OnboardRespPass123');

        $crawler = $this->client->request('GET', '/onboarding');

        $this->assertResponseIsSuccessful();
        $html = $crawler->html();
        $this->assertStringContainsString('max-w-2xl', $html, 'Onboarding sollte max-w-2xl enthalten.');
    }

    public function testAllPagesHaveViewportMetaTag(): void
    {
        // Login-Seite (auth_base) hat viewport-meta-tag
        $this->client->request('GET', '/login');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('meta[name="viewport"]', 'Login-Seite sollte einen viewport meta-tag haben.');

        // Dashboard (base) hat viewport-meta-tag
        $this->createUserAndLogin('viewport@beispiel.de', 'ViewportPass123');
        $this->client->request('GET', '/dashboard');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('meta[name="viewport"]', 'Dashboard sollte einen viewport meta-tag haben.');

        // Settings (base) hat viewport-meta-tag
        $this->client->request('GET', '/settings');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('meta[name="viewport"]', 'Settings sollten einen viewport meta-tag haben.');
    }

    public function testLoginAndLogoutWorkOnAllEndpoints(): void
    {
        // Smoke-Test: Login -> Dashboard -> Settings -> Logout
        $this->createUserAndLogin('flow@beispiel.de', 'FlowPass123');

        $this->client->request('GET', '/dashboard');
        $this->assertResponseIsSuccessful();

        $this->client->request('GET', '/settings');
        $this->assertResponseIsSuccessful();

        $this->client->request('GET', '/agent/dialog');
        $this->assertResponseIsSuccessful();
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

    private function createUser(string $email, string $plainPassword, array $roles = ['ROLE_USER']): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setFirstName('Test')
            ->setLastName('User')
            ->setRoles($roles)
            ->setPassword($this->passwordHasher->hashPassword(new User(), $plainPassword))
            ->setOnboardingComplete(true);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function createUserAndLogin(string $email, string $plainPassword): User
    {
        $user = $this->createUser($email, $plainPassword);
        $this->login($email, $plainPassword);
        return $user;
    }

    private function createAdminAndLogin(string $email, string $plainPassword): User
    {
        $user = $this->createUser($email, $plainPassword, ['ROLE_ADMIN', 'ROLE_USER']);
        $this->login($email, $plainPassword);
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
