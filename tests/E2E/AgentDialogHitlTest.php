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
 * E2E-Tests fuer den Agent-Dialog mit HITL Inline Approval (F8).
 *
 * Da WebTestCase kein JavaScript ausfuehrt, werden die statischen DOM-Elemente
 * (HITL-Container, Pending-Tools-Badge) geprueft und die Pending-Tools-Count-API
 * verifiziert. Die dynamische JS-Logik wird durch das Vorhandensein der
 * Container und der korrekten Route-Referenzen abgesichert.
 */
class AgentDialogHitlTest extends WebTestCase
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

    public function testAgentDialogPageContainsHitlApprovalContainer(): void
    {
        $this->createUserAndLogin('hitl@beispiel.de', 'HitlPass123');

        $this->client->request('GET', '/agent/dialog');

        $this->assertResponseIsSuccessful();
        // HITL Inline Approval Container muss im DOM vorhanden sein.
        $this->assertSelectorExists('#hitl-approval-container');
    }

    public function testAgentDialogPageContainsPendingToolsBadge(): void
    {
        $this->createUserAndLogin('badge@beispiel.de', 'BadgePass123');

        $this->client->request('GET', '/agent/dialog');

        $this->assertResponseIsSuccessful();
        // Pending-Tools Badge (initial hidden) muss im DOM vorhanden sein.
        $this->assertSelectorExists('#pending-tools-badge');
        $this->assertSelectorExists('#pending-tools-count');
    }

    public function testPendingToolsCountApiReturnsJson(): void
    {
        $this->createUserAndLogin('countapi@beispiel.de', 'CountApiPass123');

        $this->client->request('GET', '/api/pending-tools/count');

        $this->assertResponseIsSuccessful();
        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('count', $response);
        $this->assertIsInt($response['count']);
    }

    public function testAgentDialogReferencesApproveEndpoint(): void
    {
        $this->createUserAndLogin('approve@beispiel.de', 'ApprovePass123');

        $crawler = $this->client->request('GET', '/agent/dialog');

        $this->assertResponseIsSuccessful();
        // JS muss die approve-Endpoint-URL enthalten.
        $scriptContent = $crawler->filter('script')->each(function ($node) {
            return $node->text();
        });
        $allJs = implode("\n", $scriptContent);
        $this->assertStringContainsString('/api/tools/', $allJs);
        $this->assertStringContainsString('/approve', $allJs);
        $this->assertStringContainsString('/reject', $allJs);
    }

    public function testAnonymousAccessToAgentDialogRedirectsToLogin(): void
    {
        $this->client->request('GET', '/agent/dialog');
        $this->assertResponseRedirects('/login');
    }

    public function testAnonymousPendingToolsCountReturnsUnauthorized(): void
    {
        // API-Endpunkt liefert 401 JSON (kein Login-Redirect fuer API-Clients).
        $this->client->request('GET', '/api/pending-tools/count');
        $response = $this->client->getResponse();
        $this->assertSame(401, $response->getStatusCode(), 'API-Endpunkt ohne Auth sollte 401 liefern.');
        $this->assertSame('application/json', $response->headers->get('Content-Type'));
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
