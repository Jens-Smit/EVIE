<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Entity\UserProfile;
use App\Service\DataPrivacyService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Tests fuer DataPrivacyController (P2-1 aus Audit Zyklus 6).
 *
 * DataPrivacyController hatte bisher KEINEN einzigen Testfall in einer der 8 Suiten.
 * Dieser Test deckt die beiden Haupt-Endpunkte ab: export (Art. 15) und delete (Art. 17).
 */
class DataPrivacyControllerTest extends WebTestCase
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

    public function testExportEndpointRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/privacy/export');
        $this->assertResponseStatusCodeSame(401); // Unauthorized
    }

    public function testExportEndpointReturnsUserData(): void
    {
        $user = $this->createUser('export@test.de', 'ExportPass123');
        $this->login('export@test.de', 'ExportPass123');

        $this->client->request('GET', '/api/privacy/export');

        $this->assertResponseIsSuccessful();
        $this->assertJson($this->client->getResponse()->getContent());

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('success', $data['status']);
        $this->assertSame($user->getId(), $data['user_id']);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('user', $data['data']);
        $this->assertSame('export@test.de', $data['data']['user']['email']);
    }

    public function testDeleteEndpointRequiresAuthentication(): void
    {
        $this->client->request('DELETE', '/api/privacy/delete');
        $this->assertResponseStatusCodeSame(401); // Unauthorized
    }

    public function testDeleteEndpointDeactivatesUser(): void
    {
        $user = $this->createUser('delete@test.de', 'DeletePass123');
        $userId = $user->getId();
        $this->login('delete@test.de', 'DeletePass123');

        $this->client->request('DELETE', '/api/privacy/delete');

        $this->assertResponseIsSuccessful();
        $this->assertJson($this->client->getResponse()->getContent());

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('success', $data['status']);
        $this->assertSame($userId, $data['user_id']);
        $this->assertArrayHasKey('deleted_records', $data);

        // User sollte deaktiviert sein (nicht hart geloescht)
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $deletedUser = $em->getRepository(User::class)->find($userId);
        $this->assertNotNull($deletedUser);
        $this->assertFalse($deletedUser->isActive());
    }

    public function testDeleteEndpointWithUserProfileAndData(): void
    {
        $user = $this->createUser('deletewithdata@test.de', 'DeleteDataPass123');
        $userId = $user->getId();
        
        // UserProfile erstellen
        $profile = new UserProfile();
        $profile->setUser($user);
        $profile->setUserIdentifier('test_user_' . $userId);
        $profile->setEmail('deletewithdata@test.de');
        $this->entityManager->persist($profile);
        $this->entityManager->flush();

        $this->login('deletewithdata@test.de', 'DeleteDataPass123');

        $this->client->request('DELETE', '/api/privacy/delete');

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('success', $data['status']);
        $this->assertGreaterThan(0, $data['deleted_records']); // Sollte mindestens Profile geloescht haben

        // User sollte deaktiviert sein
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $deletedUser = $em->getRepository(User::class)->find($userId);
        $this->assertNotNull($deletedUser);
        $this->assertFalse($deletedUser->isActive());
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
