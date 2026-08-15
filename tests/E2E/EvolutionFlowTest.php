<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\ToolDefinition;
use App\Entity\User;
use App\Repository\ToolDefinitionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * E2E-Tests für den Selbst-Evolution-Flow + HITL-Blockade (Blueprint §5, §7.3).
 *
 * Verifiziert den Blueprint-Workflow "Erschaffung eines neuen Tools":
 *  1. pending-ToolDefinition (Evolution-Entwurf) ist in der Freigabe-Liste sichtbar.
 *  2. Freigabe via /api/tools/{id}/approve ändert den Status auf "approved".
 *  3. Genehmigte Tools erscheinen in der /api/tools/approved-Liste.
 *  4. Ablehnung via /api/tools/{id}/reject setzt den Status auf "rejected".
 *
 * Die HITL-Blockade selbst (deny auf ToolCallRequested) ist in
 * HitlListenerTest (Unit) abgedeckt; dieser Test prüft die User-seitige
 * Freigabe-Oberfläche und Status-Übergänge.
 */
class EvolutionFlowTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private UserPasswordHasherInterface $passwordHasher;
    private ToolDefinitionRepository $toolDefinitionRepo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->passwordHasher = $container->get(UserPasswordHasherInterface::class);
        $this->toolDefinitionRepo = $container->get(ToolDefinitionRepository::class);

        $this->ensureSchema();
        $this->purgeData();
    }

    protected function tearDown(): void
    {
        $this->purgeData();
        parent::tearDown();
    }

    public function testPendingToolAppearsInApprovalList(): void
    {
        $this->createUserAndLogin('evo1@beispiel.de', 'EvoPass123');
        $this->createPendingToolDefinition('csv_analyzer', 'Analysiert CSV-Dateien');

        $this->client->request('GET', '/tools/pending');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#content-area', 'csv_analyzer');
    }

    public function testApproveToolChangesStatusToApproved(): void
    {
        $this->createUserAndLogin('evo2@beispiel.de', 'EvoPass123');
        $tool = $this->createPendingToolDefinition('excel_parser', 'Parst Excel-Dateien');

        $this->client->request(
            'POST',
            '/api/tools/'.$tool->getId().'/approve',
            [],
            [],
            ['HTTP_ACCEPT' => 'application/json'],
        );

        self::assertResponseIsSuccessful();
        $content = json_decode($this->client->getResponse()->getContent(), true);
        self::assertTrue($content['success']);
        self::assertSame('approved', $content['status']);
        self::assertSame('excel_parser', $content['tool_name']);

        // Status persistent in der DB.
        $this->entityManager->clear();
        $reloaded = $this->toolDefinitionRepo->find($tool->getId());
        self::assertSame('approved', $reloaded->getStatus());
    }

    public function testApprovedToolAppearsInApprovedList(): void
    {
        $this->createUserAndLogin('evo3@beispiel.de', 'EvoPass123');
        $this->createApprovedToolDefinition('weather_lookup', 'Liefert Wetterdaten');

        $this->client->request('GET', '/api/tools/approved');

        self::assertResponseIsSuccessful();
        $content = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('success', $content['status'] ?? null);
        $names = array_column($content['tools'] ?? [], 'name');
        self::assertContains('weather_lookup', $names);
    }

    public function testRejectToolChangesStatusToRejected(): void
    {
        $this->createUserAndLogin('evo4@beispiel.de', 'EvoPass123');
        $tool = $this->createPendingToolDefinition('shell_exec', 'Führt Shell aus');

        $this->client->request(
            'POST',
            '/api/tools/'.$tool->getId().'/reject',
            [],
            [],
            ['HTTP_ACCEPT' => 'application/json'],
        );

        self::assertResponseIsSuccessful();
        $content = json_decode($this->client->getResponse()->getContent(), true);
        self::assertTrue($content['success']);
        self::assertSame('rejected', $content['status']);

        $this->entityManager->clear();
        $reloaded = $this->toolDefinitionRepo->find($tool->getId());
        self::assertSame('rejected', $reloaded->getStatus());
    }

    public function testApprovingAlreadyApprovedToolFails(): void
    {
        $this->createUserAndLogin('evo5@beispiel.de', 'EvoPass123');
        $tool = $this->createApprovedToolDefinition('already_approved', 'Bereits freigegeben');

        $this->client->request(
            'POST',
            '/api/tools/'.$tool->getId().'/approve',
            [],
            [],
            ['HTTP_ACCEPT' => 'application/json'],
        );

        self::assertResponseStatusCodeSame(400);
    }

    private function createPendingToolDefinition(string $name, string $description): ToolDefinition
    {
        return $this->createToolDefinition($name, $description, 'pending');
    }

    private function createApprovedToolDefinition(string $name, string $description): ToolDefinition
    {
        return $this->createToolDefinition($name, $description, 'approved');
    }

    private function createToolDefinition(string $name, string $description, string $status): ToolDefinition
    {
        $tool = (new ToolDefinition())
            ->setName($name)
            ->setDescription($description)
            ->setStatus($status)
            ->setSchema(['type' => 'object', 'properties' => []])
            ->setCategory('test')
            ->setExecutorType('generic');

        $this->entityManager->persist($tool);
        $this->entityManager->flush();

        return $tool;
    }

    private function createUserAndLogin(string $email, string $plainPassword): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setFirstName('Evo')
            ->setLastName('Tester')
            ->setPassword($this->passwordHasher->hashPassword(new User(), $plainPassword));

        $this->entityManager->persist($user);
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

    private function extractCsrfToken(Crawler $crawler, string $tokenId): string
    {
        $node = $crawler->filter('input[name="_csrf_token"]')->first();
        if ($node->count() > 0) {
            return $node->attr('value');
        }

        // Fallback: leerer Token in Umgebungen ohne CSRF (z. B. test).
        return '';
    }

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
        $conn->executeStatement('DELETE FROM users');
        $this->entityManager->clear();
    }
}
