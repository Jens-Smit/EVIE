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
 * E2E-Tests fuer die Seiten-Abdeckung der Sidebar/Navigation.
 *
 * Jeder in der Sidebar (`templates/components/_sidebar.html.twig`) sichtbare
 * Navigations-Eintrag wird einmal als authentifizierter Benutzer aufgerufen
 * und auf erfolgreiche Auslieferung sowie erwarteten Seiteninhalt geprueft.
 *
 * Abgedeckte Seiten:
 *  - Dashboard        (app_dashboard)            /dashboard
 *  - Agent Chat       (frontend_agent_dialog)   /dialog
 *  - Sub-Agenten      (app_subagents_list)      /subagents/list
 *  - Freigaben        (app_tool_pending_list)   /tools/pending
 *  - Dokumente        (app_documents)           /documents
 *  - Faehigkeiten     (app_tools_list)          /tools/list
 *  - Verlauf          (frontend_agent_history)  /history
 *  - Profil           (app_profile)             /profile
 *
 * Verwendet, wie AuthFlowTest, den Symfony Test-Client (Kernel-HTTP-Client),
 * da die Seiten kein JavaScript benoetigen.
 */
class NavigationPagesTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private UserPasswordHasherInterface $passwordHasher;

    protected function setUp(): void
    {
        parent::setUp();
        // WICHTIG: createClient() MUSS vor static::getContainer() aufgerufen werden,
        // da getContainer() den Kernel bootet und createClient() sonst fehlschlaegt.
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->passwordHasher = $container->get(UserPasswordHasherInterface::class);

        // Schema pro Test sicherstellen (erlaubt In-Memory-SQLite in jedem Env).
        $this->ensureSchema();

        // Datenbank fuer jeden Test zuruecksetzen
        $this->purgeUsers();
    }

    protected function tearDown(): void
    {
        $this->purgeUsers();
        parent::tearDown();
    }

    public function testDashboardPageLoadsAndShowsSidebar(): void
    {
        $this->createUserAndLogin('dashboard@beispiel.de', 'DashboardPass123');

        $this->client->request('GET', '/dashboard');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('#content-area h1', 'Dashboard');
        // Die Sidebar wird ueber base.html.twig eingebunden und muss rendern.
        $this->assertSidebarPresent();
        $this->assertSelectorTextContains('#nav-menu', 'Dashboard');
    }

    public function testAgentDialogPageLoads(): void
    {
        $this->createUserAndLogin('dialog@beispiel.de', 'DialogPass123');

        $this->client->request('GET', '/dialog');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('#content-area h1', 'AI Agent Dialog');
        $this->assertSidebarPresent();
    }

    public function testSubAgentsPageLoads(): void
    {
        $this->createUserAndLogin('subagents@beispiel.de', 'SubAgentsPass123');

        $this->client->request('GET', '/subagents/list');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('#content-area h1', 'Sub-Agenten verwalten');
        $this->assertSidebarPresent();
        // Die statischen Sub-Agenten werden immer vom SubAgentFactory geliefert.
        $this->assertSelectorTextContains('', 'Sub-Agenten');
    }

    public function testToolApprovalsPageLoads(): void
    {
        $this->createUserAndLogin('freigaben@beispiel.de', 'FreigabenPass123');

        $this->client->request('GET', '/tools/pending');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('#content-area h1', 'Ausstehende Tools');
        $this->assertSidebarPresent();
    }

    public function testDocumentsPageLoads(): void
    {
        $this->createUserAndLogin('dokumente@beispiel.de', 'DokumentePass123');

        $this->client->request('GET', '/documents');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('#content-area h1', 'Dokumente');
        $this->assertSidebarPresent();
    }

    public function testToolsListPageLoads(): void
    {
        $this->createUserAndLogin('tools@beispiel.de', 'ToolsPass123');

        $this->client->request('GET', '/tools/list');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('#content-area h1', 'Alle verfuegbaren Tools');
        $this->assertSidebarPresent();
    }

    public function testHistoryPageLoads(): void
    {
        $this->createUserAndLogin('verlauf@beispiel.de', 'VerlaufPass123');

        $this->client->request('GET', '/history');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('#content-area h1', 'Agenten-Verlauf');
        $this->assertSidebarPresent();
    }

    public function testProfilePageLoads(): void
    {
        $this->createUserAndLogin('profil@beispiel.de', 'ProfilPass123');

        $this->client->request('GET', '/profile');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('#content-area h1', 'Mein Profil');
        $this->assertSidebarPresent();
        // Profilseite zeigt die E-Mail-Adresse des angemeldeten Benutzers.
        $this->assertSelectorTextContains('', 'profil@beispiel.de');
    }

    public function testEverySidebarLinkPointsToALoadablePage(): void
    {
        // Stellt sicher, dass jeder in der Sidebar definierte Navigations-Link
        // tatsaechlich auf eine erfolgreiche Antwort fuehrt. So wird auch
        // zukuenftigen kaputten Routen-Referenzen (wie der ehemals fehlenden
        // `app_tools`-Route) sofort vorgebeugt.
        $this->createUserAndLogin('navlinks@beispiel.de', 'NavLinksPass123');

        // (Pfad, erwarteter Text auf der Zielseite)
        $sidebarRoutes = [
            ['/dashboard', 'Dashboard'],
            ['/dialog', 'AI Agent Dialog'],
            ['/subagents/list', 'Sub-Agenten verwalten'],
            ['/tools/pending', 'Ausstehende Tools'],
            ['/documents', 'Dokumente'],
            ['/tools/list', 'Alle verfuegbaren Tools'],
            ['/history', 'Agenten-Verlauf'],
            ['/profile', 'Mein Profil'],
        ];

        foreach ($sidebarRoutes as [$path, $expectedText]) {
            $this->client->request('GET', $path);
            $statusCode = $this->client->getResponse()->getStatusCode();
            $this->assertSame(
                200,
                $statusCode,
                sprintf('Sidebar-Link %s sollte 200 liefern, bekam aber %d.', $path, $statusCode)
            );
            $this->assertSelectorTextContains('', $expectedText);
        }
    }

    public function testAnonymousAccessToSidebarPagesRedirectsToLogin(): void
    {
        // Alle geschuetzten Seiten muessen anonyme Benutzer abweisen (Redirect zum
        // Login bzw. 401), damit kein ungeschuetzter Zugriff moeglich ist.
        $protectedPaths = [
            '/dashboard',
            '/dialog',
            '/subagents/list',
            '/tools/pending',
            '/documents',
            '/tools/list',
            '/history',
            '/profile',
        ];

        foreach ($protectedPaths as $path) {
            $this->client->request('GET', $path);
            $statusCode = $this->client->getResponse()->getStatusCode();
            $this->assertTrue(
                in_array($statusCode, [302, 401], true),
                sprintf('Anonymer Zugriff auf %s sollte 302 (Login-Redirect) oder 401 liefern, bekam %d.', $path, $statusCode)
            );
        }
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
        // Reihenfolge wegen FK beachten. tool_definitions/sub_agent_definitions
        // werden ebenfalls geleert, da registerAsTool() beim Aufruf der
        // Sub-Agenten-Seite ToolDefinition-Eintraege persistiert.
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

    /**
     * Extrahiert ein CSRF-Token aus dem gerenderten Formular-Crawler.
     *
     * Symfony benennt das CSRF-Feld nach dem Formularnamen (z. B.
     * "registration_form[_csrf_token]"), daher wird nach jedem Input
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

        // Letzter Fallback: Token ueber das CSRF-Manager-Interface erzeugen.
        // (Erfordert eine aktive Sitzung — sollte in der Regel nicht erreicht werden.)
        return static::getContainer()
            ->get('security.csrf.token_manager')
            ->getToken($tokenId)
            ->getValue();
    }

    /**
     * Stellt sicher, dass die Sidebar (und damit alle darin enthaltenen
     * Routen-Referenzen) fehlerfrei gerendert wurde. Ein fehlendes
     * `#nav-menu` weist auf eine RouteNotFoundException beim Rendern hin.
     */
    private function assertSidebarPresent(): void
    {
        $this->assertSelectorExists('#nav-menu', 'Sidebar (#nav-menu) sollte gerendert werden');
        $this->assertSelectorExists('#sidebar', 'Sidebar-Container sollte vorhanden sein');
    }
}
