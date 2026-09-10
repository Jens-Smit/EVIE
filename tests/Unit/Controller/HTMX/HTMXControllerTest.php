<?php
// tests/Unit/Controller/HTMX/HTMXControllerTest.php

declare(strict_types=1);

namespace App\Tests\Unit\Controller\HTMX;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * HTMX-Controller-Tests (Blueprint §17).
 *
 * Der HTMXController nutzt ausschliesslich $this->render() und die echten
 * Services (DynamicToolFactory, SubAgentFactory, McpToolExecutor,
 * StreamingSessionManager). Ein Unit-Test ohne Kernel kann diese Templates
 * nicht rendern, daher laufen diese Tests als WebTestCase gegen den echten
 * Container. Es werden die HTTP-Pfade verifiziert, die ohne externe Services
 * deterministisch sind: Auth-Redirects fuer anonyme User, 400 fuer fehlende
 * Eingaben und die Utils-Endpunkte (success/error/loading).
 */
class HTMXControllerTest extends WebTestCase
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

    public function testExecuteToolEndpointRequiresAuthentication(): void
    {
        $this->client->request('POST', '/htmx/tools/execute');
        $this->assertResponseRedirects('/login');
    }

    public function testExecuteToolMissingToolName(): void
    {
        $this->createUserAndLogin('htmx1@beispiel.de', 'HtmxPass123');

        $this->client->request('POST', '/htmx/tools/execute', [
            'arguments' => '[]',
        ]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testToolFormRequiresAuthentication(): void
    {
        $this->client->request('GET', '/htmx/tools/form');
        $this->assertResponseRedirects('/login');
    }

    public function testSubAgentDelegationRequiresAuthentication(): void
    {
        $this->client->request('POST', '/htmx/subagents/delegate');
        $this->assertResponseRedirects('/login');
    }

    public function testSubAgentDelegationMissingTask(): void
    {
        $this->createUserAndLogin('htmx2@beispiel.de', 'HtmxPass123');

        $this->client->request('POST', '/htmx/subagents/delegate', [
            'sub_agent_name' => 'website_researcher',
        ]);

        // Leere Aufgabe -> Fehler-Template mit 400.
        $this->assertResponseStatusCodeSame(400);
    }

    public function testExecuteMcpToolRequiresAuthentication(): void
    {
        $this->client->request('POST', '/htmx/mcp/tools/execute');
        $this->assertResponseRedirects('/login');
    }

    public function testExecuteMcpToolMissingServerOrTool(): void
    {
        $this->createUserAndLogin('htmx3@beispiel.de', 'HtmxPass123');

        $this->client->request('POST', '/htmx/mcp/tools/execute', [
            'arguments' => '[]',
        ]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testStartStreamingSessionRequiresAuthentication(): void
    {
        $this->client->request('POST', '/htmx/streaming/sessions/start');
        $this->assertResponseRedirects('/login');
    }

    public function testStartStreamingSessionMissingToolName(): void
    {
        $this->createUserAndLogin('htmx4@beispiel.de', 'HtmxPass123');

        $this->client->request('POST', '/htmx/streaming/sessions/start', [
            'arguments' => '[]',
        ]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testStreamingSessionStatusNotFound(): void
    {
        $this->createUserAndLogin('htmx5@beispiel.de', 'HtmxPass123');

        $this->client->request('GET', '/htmx/streaming/sessions/nonexistent/status');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testDashboardRequiresAuthentication(): void
    {
        $this->client->request('GET', '/htmx/dashboard');
        $this->assertResponseRedirects('/login');
    }

    public function testSuccessMessage(): void
    {
        $this->createUserAndLogin('htmx6@beispiel.de', 'HtmxPass123');

        $this->client->request('GET', '/htmx/utils/success', ['message' => 'Test success message']);

        $this->assertResponseIsSuccessful();
    }

    public function testErrorMessage(): void
    {
        $this->createUserAndLogin('htmx7@beispiel.de', 'HtmxPass123');

        $this->client->request('GET', '/htmx/utils/error', ['message' => 'Test error message']);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testLoadingIndicator(): void
    {
        $this->createUserAndLogin('htmx8@beispiel.de', 'HtmxPass123');

        $this->client->request('GET', '/htmx/utils/loading', ['message' => 'Loading...']);

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
            // Schema existiert bereits.
        }
    }

    private function purgeUsers(): void
    {
        $conn = $this->entityManager->getConnection();
        foreach ([
            'reset_password_request',
            'tool_definitions',
            'ai_sub_agent_definitions',
            'sub_agent',
            'agent_history',
            'document',
            'decision_log',
            'user_profile',
            'users',
        ] as $table) {
            try {
                $conn->executeStatement('DELETE FROM '.$table);
            } catch (\Throwable) {
                // Tabelle existiert moeglicherweise nicht in diesem Test-Setup.
            }
        }
        $this->entityManager->clear();
    }

    private function createUserAndLogin(string $email, string $plainPassword): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setFirstName('Test')
            ->setLastName('User')
            ->setOnboardingComplete(true)
            ->setPassword($this->passwordHasher->hashPassword(new User(), $plainPassword));

        $this->entityManager->persist($user);
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
