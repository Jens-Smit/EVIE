<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Tests fuer BriefingController (P2-1 aus Audit Zyklus 6).
 *
 * BriefingController hatte keine Tests. Dieser Test deckt die Haupt-Endpunkte ab:
 * dailyBriefing, weeklyBriefing, briefingDashboard, briefingSection, briefingStatistics.
 */
class BriefingControllerTest extends WebTestCase
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

    public function testBriefingDashboardRequiresAuthentication(): void
    {
        $this->client->request('GET', '/briefing');
        $this->assertResponseRedirects('/login');
    }

    public function testBriefingDashboardLoads(): void
    {
        $this->createUserAndLogin('briefing@test.de', 'BriefingPass123');

        $this->client->request('GET', '/briefing');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('', 'Briefing');
    }

    public function testDailyBriefingEndpointRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/briefing/daily');
        $this->assertResponseStatusCodeSame(401); // Unauthorized
    }

    public function testDailyBriefingEndpointReturnsData(): void
    {
        $this->createUserAndLogin('daily@test.de', 'DailyPass123');

        $this->client->request('GET', '/api/briefing/daily');

        $this->assertResponseIsSuccessful();
        $this->assertJson($this->client->getResponse()->getContent());

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('sections', $data);
    }

    public function testWeeklyBriefingEndpointRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/briefing/weekly');
        $this->assertResponseStatusCodeSame(401); // Unauthorized
    }

    public function testWeeklyBriefingEndpointReturnsData(): void
    {
        $this->createUserAndLogin('weekly@test.de', 'WeeklyPass123');

        $this->client->request('GET', '/api/briefing/weekly');

        $this->assertResponseIsSuccessful();
        $this->assertJson($this->client->getResponse()->getContent());

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('sections', $data);
    }

    public function testBriefingSectionEndpointRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/briefing/section/tool_statistics');
        $this->assertResponseStatusCodeSame(401); // Unauthorized
    }

    public function testBriefingSectionEndpointReturnsData(): void
    {
        $this->createUserAndLogin('section@test.de', 'SectionPass123');

        $this->client->request('GET', '/api/briefing/section/tool_statistics');

        $this->assertResponseIsSuccessful();
        $this->assertJson($this->client->getResponse()->getContent());
    }

    public function testBriefingStatisticsEndpointRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/briefing/statistics');
        $this->assertResponseStatusCodeSame(401); // Unauthorized
    }

    public function testBriefingStatisticsEndpointReturnsData(): void
    {
        $this->createUserAndLogin('stats@test.de', 'StatsPass123');

        $this->client->request('GET', '/api/briefing/statistics');

        $this->assertResponseIsSuccessful();
        $this->assertJson($this->client->getResponse()->getContent());
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
