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
        $this->assertSelectorTextContains('#content-area h1', 'Alle verfügbaren Tools');
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

    public function testHomePageLoads(): void
    {
        $this->createUserAndLogin('home@beispiel.de', 'HomePass123');

        $this->client->request('GET', '/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('#content-area h1', 'Willkommen bei EVIE');
        $this->assertSidebarPresent();
    }

    public function testBriefingPageLoads(): void
    {
        $this->createUserAndLogin('briefing@beispiel.de', 'BriefingPass123');

        $this->client->request('GET', '/briefing');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('#content-area h1', 'Unternehmens-Dashboard');
        $this->assertSidebarPresent();
    }

    public function testDecisionsPageLoads(): void
    {
        $this->createUserAndLogin('decisions@beispiel.de', 'DecisionsPass123');

        $this->client->request('GET', '/decisions');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('#content-area h1', 'Entscheidungs-Dashboard');
        $this->assertSidebarPresent();
    }

    public function testSubAgentsIndexPageLoads(): void
    {
        // Die aeltere /subagents-Route (Frontend\SubAgentController), die ueber
        // den Schnellzugriff des Dashboards verlinkt ist.
        $this->createUserAndLogin('subindex@beispiel.de', 'SubIndexPass123');

        $this->client->request('GET', '/subagents');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('#content-area h1', 'Subagenten');
        $this->assertSidebarPresent();
    }

    public function testMcpServersListPageLoadsForAdmin(): void
    {
        // MCP-Server-Seiten erfordern ROLE_ADMIN.
        $this->createAdminAndLogin('mcpadmin@beispiel.de', 'McpAdminPass123');

        $this->client->request('GET', '/mcp/servers');

        $this->assertResponseIsSuccessful();
        $this->assertSidebarPresent();
    }

    public function testMcpServersPageDeniedForRegularUser(): void
    {
        $this->createUserAndLogin('mcpuser@beispiel.de', 'McpUserPass123');

        $this->client->request('GET', '/mcp/servers');

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertTrue(
            in_array($statusCode, [302, 403], true),
            sprintf('REGULAR_USER darf /mcp/servers nicht sehen (302/403), bekam %d.', $statusCode)
        );
    }

    public function testSubAgentsListShowsToolAssignmentToggleAndForm(): void
    {
        // Der "Tools"-Button auf der Sub-Agenten-Seite schaltet per JS ein
        // verstecktes Zuweisungsformular ein/aus. Der Symfony-HTTP-Client fuehrt
        // kein JS aus, aber das Markup (Button + verstecktes Formular) muss
        // fuer jeden statischen Sub-Agenten vorhanden sein, damit der Toggle im
        // Browser ueberhaupt funktionieren kann.
        $this->createUserAndLogin('toggle@beispiel.de', 'TogglePass123');

        $crawler = $this->client->request('GET', '/subagents/list');

        $this->assertResponseIsSuccessful();
        // Pro Sub-Agent gibt es einen Toggle-Button und ein Zuweisungsformular.
        $toggleButtons = $crawler->filter('.toggle-tools-btn');
        $this->assertGreaterThan(
            0,
            $toggleButtons->count(),
            'Es sollte mindestens einen "Tools"-Toggle-Button geben.'
        );
        $assignmentForms = $crawler->filter('.tool-assignment-form');
        $this->assertSame(
            $toggleButtons->count(),
            $assignmentForms->count(),
            'Anzahl Toggle-Buttons und Zuweisungsformulare muss uebereinstimmen.'
        );
        // Jedes Zuweisungsformular enthaelt ein <form>, das an die
        // assign-tools-Route postet.
        $forms = $crawler->filter('.tool-assignment-form form[action]');
        $this->assertSame(
            $toggleButtons->count(),
            $forms->count(),
            'Anzahl Toggle-Buttons und <form>-Elemente muss uebereinstimmen.'
        );
        foreach ($forms as $formNode) {
            $action = $formNode->getAttribute('action') ?? '';
            $this->assertStringContainsString('/subagents/', $action);
            $this->assertStringContainsString('/assign-tools', $action);
        }
    }

    public function testSubAgentToolAssignmentEndpointAcceptsPost(): void
    {
        // Verifiziert den POST-Endpunkt, den das Zuweisungsformular ansteuert.
        $this->createUserAndLogin('assign@beispiel.de', 'AssignPass123');

        // Zunaechst die Liste laden, damit die statischen Sub-Agenten registriert werden.
        $this->client->request('GET', '/subagents/list');

        // Einen der statischen Sub-Agenten-Namen fuer die Zuweisung verwenden.
        $this->client->request('POST', '/subagents/website_researcher/assign-tools', [
            'tools' => [],
        ]);

        // Erfolgreiche Zuweisung leitet zur Liste zurueck.
        $this->assertResponseRedirects('/subagents/list');
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();
    }

    public function testEveryFrontendPageLinkPointsToALoadablePage(): void
    {
        // Wie testEverySidebarLinkPointsToALoadablePage, aber fuer die
        // zusaetzlich im Frontend verfuegbaren Seiten (Home, Briefing,
        // Entscheidungen, aeltere Sub-Agenten-Seite). Passwort-Reset-Anfrage
        // ist bewusst ausgenommen.
        $this->createUserAndLogin('feall@beispiel.de', 'FeAllPass123');

        $frontendPages = [
            ['/', 'Willkommen bei EVIE'],
            ['/briefing', 'Unternehmens-Dashboard'],
            ['/decisions', 'Entscheidungs-Dashboard'],
            ['/subagents', 'Subagenten'],
        ];

        foreach ($frontendPages as [$path, $expectedText]) {
            $this->client->request('GET', $path);
            $statusCode = $this->client->getResponse()->getStatusCode();
            $this->assertSame(
                200,
                $statusCode,
                sprintf('Frontend-Seite %s sollte 200 liefern, bekam aber %d.', $path, $statusCode)
            );
            $this->assertSelectorTextContains('#content-area h1', $expectedText);
        }
    }

    public function testEveryFrontendPageLinkRedirectsAnonymousToLogin(): void
    {
        $protectedPaths = [
            '/',
            '/briefing',
            '/decisions',
            '/subagents',
            '/mcp/servers',
        ];

        foreach ($protectedPaths as $path) {
            $this->client->request('GET', $path);
            $statusCode = $this->client->getResponse()->getStatusCode();
            $this->assertSame(
                302,
                $statusCode,
                sprintf('Anonymer Zugriff auf %s sollte 302 (Login-Redirect) liefern, bekam %d.', $path, $statusCode)
            );
            // Redirect muss zum /login fuehren.
            $this->assertStringEndsWith('/login', $this->client->getResponse()->headers->get('Location', ''));
        }
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
            ['/tools/list', 'Alle verfügbaren Tools'],
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
        // Alle geschuetzten Seiten muessen anonyme Benutzer zum Login weiterleiten
        // (302 -> /login). 401 ist nicht akzeptabel, da es die rohe Symfony-Debug-
        // Seite zeigen wuerde (F1 aus Frontend-Audit).
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
            $this->assertSame(
                302,
                $statusCode,
                sprintf('Anonymer Zugriff auf %s sollte 302 (Login-Redirect) liefern, bekam %d.', $path, $statusCode)
            );
            $location = $this->client->getResponse()->headers->get('Location', '');
            $this->assertStringEndsWith('/login', $location, sprintf('Redirect fuer %s sollte auf /login zeigen, ist aber: %s', $path, $location));
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

    private function createAdminAndLogin(string $email, string $plainPassword): User
    {
        $user = $this->createUser($email, $plainPassword);
        $user->setRoles(['ROLE_ADMIN']);
        $this->entityManager->flush();

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
