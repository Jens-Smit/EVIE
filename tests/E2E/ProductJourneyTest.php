<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\Document;
use App\Entity\ToolDefinition;
use App\Entity\User;
use App\Entity\UserProfile;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * E2E-Tests fuer die Produkt-Journey aus dem Frontend-Audit:
 *
 *  P0.1  Tool-Freigabe-Endpunkte sind CSRF-gesichert
 *  P0.2  Zentrale Freigabe-Inbox /approvals (Tools + Entscheidungen)
 *  P0.3  Chat zeigt Freigabe-Karten-Hook (requires_tool_approval)
 *  P0.4  Sidebar-Badge wird serverseitig gerendert (kein JSON-Swap mehr)
 *  P1.1  Sidebar trennt Benutzer- und Administration-Ebene
 *  P1.2  Dashboard als Command Center (Aufgaben-Eingabe -> Chat)
 *  P1.3  Entscheidungs-Details als Modal statt alert()
 *  P1.4  Dokumente verlinken EVIE-Aktionen
 *  P2.1  Keine CDN-Abhaengigkeiten mehr (Assets lokal)
 *  P2.2  Dark Mode persistent (localStorage)
 */
final class ProductJourneyTest extends WebTestCase
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
        $this->purgeData();
    }

    protected function tearDown(): void
    {
        $this->purgeData();
        parent::tearDown();
        static::ensureKernelShutdown();
    }

    // ------------------------------------------------------------------
    // P0.1: Tool-Freigabe-Endpunkte sind CSRF-gesichert
    // ------------------------------------------------------------------

    public function testToolApproveRequiresCsrfToken(): void
    {
        $user = $this->createUserAndLogin('csrf-approve@beispiel.de', 'CsrfPass123');
        $tool = $this->createPendingTool('csrf-tool-approve', 'CSRF-Test-Faehigkeit');

        // Ohne Token: 403
        $this->client->request('POST', '/tools/pending/' . $tool->getId() . '/approve', [], [], [
            'HTTP_X-Requested-With' => 'XMLHttpRequest',
        ]);
        $this->assertResponseStatusCodeSame(403, 'Approve ohne CSRF-Token muss 403 liefern.');

        // Mit Token: 200 und Status approved
        $token = $this->getCsrfToken('tool_approval');
        $this->client->request('POST', '/tools/pending/' . $tool->getId() . '/approve', [
            '_token' => $token,
        ], [], [
            'HTTP_X-Requested-With' => 'XMLHttpRequest',
        ]);
        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('success', $data['status']);
        $this->assertSame('approved', $data['tool']['status']);
    }

    public function testToolRejectRequiresCsrfToken(): void
    {
        $this->createUserAndLogin('csrf-reject@beispiel.de', 'CsrfPass123');
        $tool = $this->createPendingTool('csrf-tool-reject', 'CSRF-Test-Faehigkeit');

        $this->client->request('POST', '/tools/pending/' . $tool->getId() . '/reject', [
            'reason' => 'Nicht noetig',
        ], [], [
            'HTTP_X-Requested-With' => 'XMLHttpRequest',
        ]);
        $this->assertResponseStatusCodeSame(403, 'Reject ohne CSRF-Token muss 403 liefern.');

        $token = $this->getCsrfToken('tool_approval');
        $this->client->request('POST', '/tools/pending/' . $tool->getId() . '/reject', [
            '_token' => $token,
            'reason' => 'Nicht noetig',
        ], [], [
            'HTTP_X-Requested-With' => 'XMLHttpRequest',
        ]);
        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('rejected', $data['tool']['status']);
    }

    public function testDoubleApprovalIsRejectedAsConflict(): void
    {
        $this->createUserAndLogin('conflict@beispiel.de', 'ConflictPass123');
        $tool = $this->createPendingTool('conflict-tool', 'Konflikt-Test-Faehigkeit');
        $token = $this->getCsrfToken('tool_approval');

        $this->client->request('POST', '/tools/pending/' . $tool->getId() . '/approve', [
            '_token' => $token,
        ], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);
        $this->assertResponseIsSuccessful();

        // Zweiter Approve auf dasselbe Tool: 409 (idempotenter Zustandsfehler)
        $this->client->request('POST', '/tools/pending/' . $tool->getId() . '/approve', [
            '_token' => $token,
        ], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);
        $this->assertResponseStatusCodeSame(409, 'Doppelte Freigabe muss 409 liefern.');
    }

    // ------------------------------------------------------------------
    // P0.2: Zentrale Freigabe-Inbox
    // ------------------------------------------------------------------

    public function testApprovalsInboxListsPendingTools(): void
    {
        $this->createUserAndLogin('inbox@beispiel.de', 'InboxPass123');
        $this->createPendingTool('inbox-tool', 'Faehigkeit fuer die Inbox');

        $crawler = $this->client->request('GET', '/approvals');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('#content-area h1', 'Freigaben');
        $this->assertSelectorExists('.approval-item[data-kind="tool"]');
        $this->assertSelectorTextContains('', 'Neue Fähigkeit');
    }

    public function testApprovalsInboxEmptyState(): void
    {
        $this->createUserAndLogin('inbox-empty@beispiel.de', 'InboxEmptyPass123');
        $this->client->request('GET', '/approvals');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('', 'Keine Freigaben erforderlich');
    }

    public function testApprovalsInboxLinksToToolDetails(): void
    {
        $this->createUserAndLogin('inbox-link@beispiel.de', 'InboxLinkPass123');
        $tool = $this->createPendingTool('inbox-link-tool', 'Faehigkeit mit Detail-Link');

        $crawler = $this->client->request('GET', '/approvals');
        $this->assertResponseIsSuccessful();
        $detailLink = $crawler->selectLink('Prüfen')->link();
        $this->assertStringContainsString('/tools/pending/' . $tool->getId(), $detailLink->getUri());

        // Detail-Seite rendert Was/Warum/Risiko und CSRF-gesicherte Formulare
        $this->client->request('GET', '/tools/pending/' . $tool->getId());
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('#content-area h1', 'Fähigkeit erforderlich');
        $this->assertSelectorTextContains('', 'Risiko');
        $this->assertSelectorExists('form[action*="/approve"] input[name="_token"]');
        $this->assertSelectorExists('form[action*="/reject"] input[name="_token"]');
    }

    public function testAnonymousApprovalsRedirectsToLogin(): void
    {
        $this->client->request('GET', '/approvals');
        $this->assertResponseRedirects('/login');
    }

    // ------------------------------------------------------------------
    // P0.1: Pending-Liste rendert serverseitig inkl. CSRF-Token je Button
    // ------------------------------------------------------------------

    public function testPendingListRendersRiskAndCsrfTokens(): void
    {
        $this->createUserAndLogin('pending@beispiel.de', 'PendingPass123');
        $tool = $this->createPendingTool('pending-list-tool', 'Faehigkeit mit Risiko');
        $tool->setSecurityLevel('medium');
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/tools/pending');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('', 'Risiko');
        $this->assertSelectorTextContains('', 'Mittel');
        $approveBtn = $crawler->filter('.approve-tool[data-token]');
        $this->assertGreaterThan(0, $approveBtn->count(), 'Approve-Button muss ein CSRF-Token rendern.');
    }

    // ------------------------------------------------------------------
    // P0.4: Sidebar-Badge serverseitig
    // ------------------------------------------------------------------

    public function testSidebarBadgeShowsServerRenderedCount(): void
    {
        $this->createUserAndLogin('badge@beispiel.de', 'BadgePass123');
        $this->createPendingTool('badge-tool', 'Faehigkeit fuer den Badge');

        $crawler = $this->client->request('GET', '/dashboard');
        $this->assertResponseIsSuccessful();
        $badge = $crawler->filter('#sidebar-pending-count');
        $this->assertSame(1, $badge->count(), 'Sidebar-Badge muss serverseitig gerendert werden.');
        $this->assertSame('1', trim($badge->text()));

        // Kein HTMX-JSON-Swap mehr (das war der kaputte Swap)
        $this->assertStringNotContainsString(
            'hx-get="/api/pending-tools/count"',
            (string) $this->client->getResponse()->getContent(),
            'Der Badge darf nicht mehr per JSON-Swap geladen werden.'
        );
    }

    // ------------------------------------------------------------------
    // P1.1: Sidebar-Ebenen
    // ------------------------------------------------------------------

    public function testRegularUserDoesNotSeeAdminSection(): void
    {
        $this->createUserAndLogin('user-level@beispiel.de', 'UserLevelPass123');
        $crawler = $this->client->request('GET', '/dashboard');
        $this->assertResponseIsSuccessful();

        $content = (string) $this->client->getResponse()->getContent();
        $this->assertStringNotContainsString('Integrationen', $content, 'Normaler User darf MCP nicht sehen.');
        $this->assertStringNotContainsString('Sicherheit</span>', $content, 'Normaler User darf Audit-Logs nicht sehen.');
        $this->assertStringNotContainsString('Nutzung</span>', $content, 'Normaler User darf Quota nicht sehen.');
        $this->assertStringContainsString('Freigaben', $content);
        $this->assertStringContainsString('Dokumente', $content);
        $this->assertStringContainsString('Automatisierungen', $content);
    }

    public function testAdminSeesAdminSectionWithProductLanguage(): void
    {
        $this->createAdminAndLogin('admin-level@beispiel.de', 'AdminLevelPass123');
        $this->client->request('GET', '/dashboard');
        $this->assertResponseIsSuccessful();

        $content = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Administration', $content);
        $this->assertStringContainsString('Integrationen', $content, 'Admin sieht MCP als Integrationen.');
        $this->assertStringContainsString('Fähigkeiten', $content, 'Admin sieht Tools als Fähigkeiten.');
        $this->assertStringContainsString('Mitarbeiter', $content, 'Admin sieht Sub-Agenten als Mitarbeiter.');
        // Entwickler-Begriffe duerfen in der Navigation nicht mehr auftauchen
        $this->assertStringNotContainsString('>MCP-Server<', $content);
        $this->assertStringNotContainsString('>Sub-Agenten<', $content);
        $this->assertStringNotContainsString('>Quota<', $content);
        $this->assertStringNotContainsString('>Secrets<', $content);
        $this->assertStringNotContainsString('>Audit-Logs<', $content);
    }

    // ------------------------------------------------------------------
    // P1.2: Dashboard Command Center
    // ------------------------------------------------------------------

    public function testDashboardShowsTaskInputLeadingToChat(): void
    {
        $this->createUserAndLogin('cc@beispiel.de', 'CcPass123');
        $crawler = $this->client->request('GET', '/dashboard');
        $this->assertResponseIsSuccessful();

        $form = $crawler->filter('#dashboard-task-form');
        $this->assertSame(1, $form->count(), 'Dashboard muss das Command-Center-Formular enthalten.');
        $action = $form->attr('action');
        $this->assertNotNull($action);
        $this->assertStringContainsString('/dialog', $action, 'Aufgaben-Eingabe muss in den Agent-Chat fuehren.');

        // Freigaben-Warnbanner bei offenen Freigaben
        $this->createPendingTool('cc-tool', 'Command-Center-Faehigkeit');
        $crawler = $this->client->request('GET', '/dashboard');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('', 'deine Entscheidung');
    }

    public function testDialogPrefillsTaskFromQuery(): void
    {
        $this->createUserAndLogin('prefill@beispiel.de', 'PrefillPass123');
        $crawler = $this->client->request('GET', '/dialog?task=' . urlencode('Fasse die neuen Gutachten zusammen.'));
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('#prompt', 'Fasse die neuen Gutachten zusammen.');
    }

    // ------------------------------------------------------------------
    // P1.3: Entscheidungen als Modal
    // ------------------------------------------------------------------

    public function testDecisionDashboardUsesModalInsteadOfAlert(): void
    {
        $this->createUserAndLogin('modal@beispiel.de', 'ModalPass123');
        $crawler = $this->client->request('GET', '/decisions');
        $this->assertResponseIsSuccessful();

        $content = (string) $this->client->getResponse()->getContent();
        $this->assertStringNotContainsString('alert(', $content, 'Decision-Details duerfen kein alert() nutzen.');
        $this->assertStringNotContainsString('prompt(', $content, 'Reject duerfen kein prompt() nutzen.');
        $this->assertStringContainsString('openModal', $content, 'Decision-Details muessen das Modal nutzen.');
        $this->assertSelectorExists('#global-modal', 'Globales Modal muss eingebunden sein.');
        $this->assertSelectorExists('#modal-confirm-btn');
    }

    // ------------------------------------------------------------------
    // P1.4: Dokumente <-> EVIE
    // ------------------------------------------------------------------

    public function testDocumentsPageLinksEvieActions(): void
    {
        $this->createUserAndLogin('doc-evie@beispiel.de', 'DocEviePass123');
        $this->createDocument('Gutachten_2026.pdf');
        $crawler = $this->client->request('GET', '/documents');

        $this->assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Zusammenfassen', $content, 'Dokumente muessen EVIE-Aktionen anbieten.');
        $this->assertStringContainsString('/dialog?task=', $content, 'EVIE-Aktionen muessen in den Chat fuehren.');
        $this->assertStringContainsString('Gutachten_2026.pdf', $content);
    }

    // ------------------------------------------------------------------
    // P2.1: Lokale Assets statt CDN
    // ------------------------------------------------------------------

    public function testBaseTemplateHasNoCdnDependencies(): void
    {
        $this->createUserAndLogin('assets@beispiel.de', 'AssetsPass123');
        $this->client->request('GET', '/dashboard');
        $this->assertResponseIsSuccessful();

        $content = (string) $this->client->getResponse()->getContent();
        $this->assertStringNotContainsString('unpkg.com', $content, 'unpkg.com darf nicht mehr referenziert werden.');
        $this->assertStringNotContainsString('cdn.jsdelivr.net', $content, 'jsdelivr darf nicht mehr referenziert werden.');
        $this->assertStringContainsString('assets/vendor/htmx/htmx.min.js', $content);
        $this->assertStringContainsString('assets/vendor/alpine/alpine.min.js', $content);
        $this->assertStringContainsString('assets/vendor/phosphor/phosphor.css', $content);
    }

    public function testLocalVendorAssetsAreServed(): void
    {
        $paths = [
            '/assets/vendor/htmx/htmx.min.js',
            '/assets/vendor/alpine/alpine.min.js',
            '/assets/vendor/phosphor/phosphor.css',
            '/assets/vendor/phosphor/fonts/Phosphor.woff2',
        ];
        foreach ($paths as $path) {
            $this->client->request('GET', $path);
            $this->assertResponseIsSuccessful(sprintf('Asset %s muss lokal ausgeliefert werden.', $path));
        }
    }

    // ------------------------------------------------------------------
    // P2.2: Dark Mode persistent
    // ------------------------------------------------------------------

    public function testBaseTemplatePersistsThemeChoice(): void
    {
        $this->createUserAndLogin('theme@beispiel.de', 'ThemePass123');
        $this->client->request('GET', '/dashboard');
        $this->assertResponseIsSuccessful();

        $content = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString("localStorage.setItem('evie-theme'", $content,
            'Theme-Wahl muss persistiert werden.');
        $this->assertStringContainsString("localStorage.getItem('evie-theme')", $content,
            'Theme muss beim Laden aus dem localStorage wiederhergestellt werden.');
    }

    // ------------------------------------------------------------------
    // P0.3: Chat-Freigabe-Hook (Markup), Inline-Approval sendet CSRF
    // ------------------------------------------------------------------

    public function testDialogJsHandlesRequiresToolApprovalAndSendsCsrf(): void
    {
        $this->createUserAndLogin('chat-hitl@beispiel.de', 'ChatHitlPass123');
        $crawler = $this->client->request('GET', '/dialog');
        $this->assertResponseIsSuccessful();

        $allJs = implode("\n", $crawler->filter('script')->each(static fn ($node) => (string) $node->text()));
        $this->assertStringContainsString('requires_tool_approval', $allJs,
            'Chat-JS muss requires_tool_approval verarbeiten (neues API-Feld).');
        $this->assertStringContainsString("body.set('_token'", $allJs,
            'Inline-Freigabe im Chat muss ein CSRF-Token senden.');
        $this->assertStringContainsString('csrf_token(\'tool_approval\')', $allJs);
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

    private function purgeData(): void
    {
        $conn = $this->entityManager->getConnection();
        $conn->executeStatement('DELETE FROM reset_password_request');
        $conn->executeStatement('DELETE FROM tool_definitions');
        $conn->executeStatement('DELETE FROM ai_sub_agent_definitions');
        $conn->executeStatement('DELETE FROM sub_agent');
        $conn->executeStatement('DELETE FROM agent_history');
        $conn->executeStatement('DELETE FROM document');
        $conn->executeStatement('DELETE FROM decision_log');
        $conn->executeStatement('DELETE FROM user_profile');
        $conn->executeStatement('DELETE FROM users');
        $this->entityManager->clear();
    }

    private function createUser(string $email, string $plainPassword): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setFirstName('Test')
            ->setLastName('User')
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
        $user = $this->createUser($email, $plainPassword);
        $user->setRoles(['ROLE_ADMIN']);
        $this->entityManager->flush();
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
        $this->client->followRedirect();
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

    private function getCsrfToken(string $tokenId): string
    {
        // Der Token-Manager benoetigt eine aktive Session. Wir holen das
        // Token daher ueber eine gerenderte Seite (die das Token als
        // data-token-Attribut bzw. Hidden-Field enthaelt), damit der Helper
        // auch ohne vorherige Session-nutzende Request funktioniert.
        $crawler = $this->client->request('GET', '/tools/pending');
        $btn = $crawler->filter('.approve-tool[data-token]')->first();
        if ($btn->count() > 0) {
            $token = $btn->attr('data-token');
            if (is_string($token) && $token !== '') {
                return $token;
            }
        }

        return static::getContainer()
            ->get('security.csrf.token_manager')
            ->getToken($tokenId)
            ->getValue();
    }

    private function createPendingTool(string $name, string $description): ToolDefinition
    {
        $tool = new ToolDefinition();
        $tool->setName($name);
        $tool->setDescription($description);
        $tool->setStatus('pending');
        $tool->setSchema(['type' => 'object', 'properties' => []]);
        $tool->setSecurityLevel('low');
        $this->entityManager->persist($tool);
        $this->entityManager->flush();

        return $tool;
    }

    private function createDocument(string $name): void
    {
        $document = new Document();
        $document->setName($name);
        $document->setContent('Beispielinhalt');
        $document->setUser($this->getOrCreateUserProfile());
        $this->entityManager->persist($document);
        $this->entityManager->flush();
    }

    private function getOrCreateUserProfile(): UserProfile
    {
        $user = $this->entityManager->createQuery('SELECT u FROM App\Entity\User u ORDER BY u.id ASC')
            ->setMaxResults(1)
            ->getSingleResult();

        $profile = $this->entityManager
            ->createQuery('SELECT p FROM App\Entity\UserProfile p WHERE p.userIdentifier = :ident')
            ->setParameter('ident', $user->getUserIdentifier())
            ->getOneOrNullResult();

        if ($profile === null) {
            $profile = new UserProfile();
            $profile->setUserIdentifier($user->getUserIdentifier());
            $profile->setName($user->getUserIdentifier());
            $this->entityManager->persist($profile);
            $this->entityManager->flush();
        }

        return $profile;
    }
}
