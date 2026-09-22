<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Secret;
use App\Entity\User;
use App\Service\SecretService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Functional-Tests fuer den SecretController-Update-Endpunkt (Luecke 1:
 * Secrets lassen sich im Frontend bearbeiten, nicht nur anlegen/loeschen).
 */
class SecretControllerUpdateTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private UserPasswordHasherInterface $passwordHasher;
    private SecretService $secretService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->passwordHasher = $container->get(UserPasswordHasherInterface::class);
        $this->secretService = $container->get(SecretService::class);
        $this->ensureSchema();
        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();
        parent::tearDown();
        static::ensureKernelShutdown();
    }

    public function testUpdateChangesExistingSecretValue(): void
    {
        $email = 'secret-update@test.de';
        $user = $this->createUserAndLogin($email, 'SecretPass123');
        $userIdentifier = $user->getUserIdentifier();

        $this->secretService->set('MISTRAL_API_KEY', 'old-key-value', $userIdentifier, 'llm');

        $this->client->request('POST', '/settings/secrets/MISTRAL_API_KEY/update', [
            'value' => 'new-key-value',
            'scope' => 'llm',
        ]);

        $this->assertResponseRedirects('/settings/secrets');

        $updated = $this->entityManager->getRepository(Secret::class)
            ->findOneByKeyAndUser('MISTRAL_API_KEY', $userIdentifier);
        self::assertNotNull($updated);
        // Identity-Map leeren: Der Request aktualisiert die Secret-Entity im
        // Unit-of-Work des Requests; die hier gecachte Instanz traegt noch den
        // alten verschluesselten Wert.
        $this->entityManager->clear();
        self::assertSame('new-key-value', $this->secretService->get('MISTRAL_API_KEY', $userIdentifier));
    }

    public function testUpdateRejectsEmptyValue(): void
    {
        $email = 'secret-empty@test.de';
        $user = $this->createUserAndLogin($email, 'SecretPass123');
        $userIdentifier = $user->getUserIdentifier();

        $this->secretService->set('openweather_api_key', 'orig-value', $userIdentifier);

        $this->client->request('POST', '/settings/secrets/openweather_api_key/update', [
            'value' => '',
        ]);

        $this->assertResponseRedirects('/settings/secrets');
        self::assertSame('orig-value', $this->secretService->get('openweather_api_key', $userIdentifier));
    }

    public function testUpdateRejectsNonExistentSecret(): void
    {
        $email = 'secret-missing@test.de';
        $this->createUserAndLogin($email, 'SecretPass123');

        $this->client->request('POST', '/settings/secrets/does_not_exist/update', [
            'value' => 'whatever',
        ]);

        $this->assertResponseRedirects('/settings/secrets');
        self::assertNull($this->secretService->get('does_not_exist', $email));
    }

    public function testSecretsPageShowsEditButton(): void
    {
        $email = 'secret-render@test.de';
        $user = $this->createUserAndLogin($email, 'SecretPass123');
        $userIdentifier = $user->getUserIdentifier();

        $this->secretService->set('tavily_api_key', 'tavily-value', $userIdentifier);

        $this->client->request('GET', '/settings/secrets');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('', 'Bearbeiten');
        $this->assertSelectorExists('form[action$="/settings/secrets/tavily_api_key/update"]');
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

    private function purge(): void
    {
        $conn = $this->entityManager->getConnection();
        $conn->executeStatement('DELETE FROM reset_password_request');
        $conn->executeStatement('DELETE FROM secrets');
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
