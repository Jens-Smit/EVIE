<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\AI\Decision\DecisionManager;
use App\Entity\DecisionLog;
use App\Entity\User;
use App\Entity\UserProfile;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Tests fuer DecisionController (P2-1 aus Audit Zyklus 6).
 *
 * DecisionController hatte nur 10,2% Testabdeckung. Dieser Test deckt die
 * Haupt-Endpunkte ab: pending, by_type, recent, statistics, approve, reject, show, check.
 */
class DecisionControllerTest extends WebTestCase
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

    public function testDecisionsDashboardRequiresAuthentication(): void
    {
        $this->client->request('GET', '/decisions');
        $this->assertResponseRedirects('/login');
    }

    public function testDecisionsDashboardLoads(): void
    {
        $this->createUserAndLogin('decision@test.de', 'DecisionPass123');

        $this->client->request('GET', '/decisions');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('', 'Entscheidungen');
    }

    public function testPendingDecisionsEndpointRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/decisions/pending');
        $this->assertResponseStatusCodeSame(401); // Unauthorized
    }

    public function testPendingDecisionsEndpointReturnsEmptyArray(): void
    {
        $this->createUserAndLogin('pending@test.de', 'PendingPass123');

        $this->client->request('GET', '/api/decisions/pending');

        $this->assertResponseIsSuccessful();
        $this->assertJson($this->client->getResponse()->getContent());

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('success', $data['status']);
        $this->assertSame(0, $data['count']);
        $this->assertSame([], $data['decisions']);
    }

    public function testDecisionsByTypeEndpointReturnsEmptyArray(): void
    {
        $this->createUserAndLogin('type@test.de', 'TypePass123');

        $this->client->request('GET', '/api/decisions/type/tool_approval');

        $this->assertResponseIsSuccessful();
        $this->assertJson($this->client->getResponse()->getContent());

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('success', $data['status']);
        $this->assertSame('tool_approval', $data['type']);
        $this->assertSame(0, $data['count']);
    }

    public function testRecentDecisionsEndpointReturnsEmptyArray(): void
    {
        $this->createUserAndLogin('recent@test.de', 'RecentPass123');

        $this->client->request('GET', '/api/decisions/recent?limit=5');

        $this->assertResponseIsSuccessful();
        $this->assertJson($this->client->getResponse()->getContent());

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('success', $data['status']);
        $this->assertSame(0, $data['count']);
    }

    public function testStatisticsEndpointReturnsData(): void
    {
        $this->createUserAndLogin('stats@test.de', 'StatsPass123');

        $this->client->request('GET', '/api/decisions/statistics');

        $this->assertResponseIsSuccessful();
        $this->assertJson($this->client->getResponse()->getContent());

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('success', $data['status']);
        $this->assertArrayHasKey('statistics', $data);
    }

    public function testCheckPendingEndpointReturnsNoPending(): void
    {
        $this->createUserAndLogin('check@test.de', 'CheckPass123');

        $this->client->request('GET', '/api/decisions/check');

        $this->assertResponseIsSuccessful();
        $this->assertJson($this->client->getResponse()->getContent());

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('success', $data['status']);
        $this->assertFalse($data['has_pending']);
        $this->assertSame(0, $data['count']);
    }

    public function testShowDecisionEndpointReturnsNotFound(): void
    {
        $this->createUserAndLogin('show@test.de', 'ShowPass123');

        $this->client->request('GET', '/api/decisions/99999');

        $this->assertResponseStatusCodeSame(404);
        $this->assertJson($this->client->getResponse()->getContent());

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('error', $data['status']);
        $this->assertStringContainsString('nicht gefunden', $data['message']);
    }

    public function testApproveDecisionEndpointReturnsNotFound(): void
    {
        $this->createUserAndLogin('approve@test.de', 'ApprovePass123');

        $this->client->request('POST', '/api/decisions/99999/approve');

        $this->assertResponseStatusCodeSame(404);
        $this->assertJson($this->client->getResponse()->getContent());

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('error', $data['status']);
    }

    public function testRejectDecisionEndpointReturnsNotFound(): void
    {
        $this->createUserAndLogin('reject@test.de', 'RejectPass123');

        $this->client->request('POST', '/api/decisions/99999/reject', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['reason' => 'Test reason']));

        $this->assertResponseStatusCodeSame(404);
        $this->assertJson($this->client->getResponse()->getContent());

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('error', $data['status']);
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
            ->setPassword($this->passwordHasher->hashPassword(new User(), $plainPassword));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function createUserAndLogin(string $email, string $plainPassword): User
    {
        $user = $this->createUser($email, $plainPassword);
        $this->login($email, $plainPassword);
        // Onboarding-Redirect ueberspringen
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

    private function extractCsrfToken(\Symfony\Component\DomCrawler\Crawler $crawler): string
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
