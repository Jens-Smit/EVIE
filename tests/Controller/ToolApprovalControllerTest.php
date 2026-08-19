<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ToolDefinition;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Tests fuer ToolApprovalController (P2-1 aus Audit Zyklus 6).
 *
 * ToolApprovalController hatte nur 5,8% Testabdeckung. Dieser Test deckt die
 * Haupt-Endpunkte ab: listPending, handleApproval, showTool, getToolStatus.
 */
class ToolApprovalControllerTest extends WebTestCase
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

    public function testPendingListPageLoads(): void
    {
        $this->createUserAndLogin('tool@test.de', 'ToolPass123');

        $this->client->request('GET', '/tools/pending');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('', 'Freigaben');
    }

    public function testPendingListApiReturnsEmptyArray(): void
    {
        $this->createUserAndLogin('toolapi@test.de', 'ToolApiPass123');

        $this->client->request('GET', '/tools/pending', [], [], [
            'HTTP_X-Requested-With' => 'XMLHttpRequest',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertJson($this->client->getResponse()->getContent());

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame(0, $data['count']);
        $this->assertSame([], $data['tools']);
    }

    public function testPendingToolsCountApiReturnsZero(): void
    {
        $this->createUserAndLogin('count@test.de', 'CountPass123');

        $this->client->request('GET', '/api/pending-tools/count');

        $this->assertResponseIsSuccessful();
        $this->assertJson($this->client->getResponse()->getContent());

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('success', $data['status']);
        $this->assertSame(0, $data['count']);
    }

    public function testApprovedToolsListApiReturnsEmptyArray(): void
    {
        $this->createUserAndLogin('approved@test.de', 'ApprovedPass123');

        $this->client->request('GET', '/api/tools/approved');

        $this->assertResponseIsSuccessful();
        $this->assertJson($this->client->getResponse()->getContent());

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('success', $data['status']);
        $this->assertSame(0, $data['count']);
        $this->assertSame([], $data['tools']);
    }

    public function testToolStatusEndpointReturnsNotFound(): void
    {
        $this->createUserAndLogin('status@test.de', 'StatusPass123');

        $this->client->request('GET', '/api/tools/99999/status');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testToolApprovalEndpointReturnsNotFound(): void
    {
        $this->createUserAndLogin('approve@test.de', 'ApprovePass123');

        $this->client->request('POST', '/api/tools/99999/approve', [], [], [
            'HTTP_X-Requested-With' => 'XMLHttpRequest',
        ]);

        $this->assertResponseStatusCodeSame(404);
    }

    public function testToolRejectEndpointReturnsNotFound(): void
    {
        $this->createUserAndLogin('reject@test.de', 'RejectPass123');

        $this->client->request('POST', '/api/tools/99999/reject', [], [], [
            'HTTP_X-Requested-With' => 'XMLHttpRequest',
        ]);

        $this->assertResponseStatusCodeSame(404);
    }

    public function testToolShowEndpointReturnsNotFound(): void
    {
        $this->createUserAndLogin('show@test.de', 'ShowPass123');

        $this->client->request('GET', '/tools/99999/show');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testToolResetEndpointReturnsNotFound(): void
    {
        $this->createUserAndLogin('reset@test.de', 'ResetPass123');

        $this->client->request('POST', '/api/tools/99999/reset', [], [], [
            'HTTP_X-Requested-With' => 'XMLHttpRequest',
        ]);

        $this->assertResponseStatusCodeSame(404);
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
