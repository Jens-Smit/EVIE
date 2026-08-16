<?php

declare(strict_types=1);

namespace App\Tests\Integration\AI\Rag;

use App\AI\Rag\ContextInjector;
use App\AI\Rag\EmbeddingServiceInterface;
use App\AI\Rag\VectorStore;
use App\Entity\Embedding;
use App\Repository\EmbeddingRepository;
use App\Security\UserContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\AI\Agent\Input;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\Role;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * P0-1 Integrationstest: RAG-Tenant-Isolation ueber den echten
 * ContextInjector-Pfad (Blueprint Tenant-Isolation, audit.md 2.1).
 *
 * Dieser Test beweist, dass der RAG-Kontext pro Tenant isoliert abgerufen
 * wird. Er laeuft ueber die echte InputProcessor-Kette:
 *   ContextInjector::processInput()
 *     -> Retriever::retrieve(['user_identifier' => $tenant])
 *       -> VectorStore::search(..., $userIdentifier)
 *         -> EmbeddingRepository::findSimilar(..., $userIdentifier)
 *
 * Damit wird genau der False-Positive-Fehler des bisherigen
 * TenantIsolationTest.php vermieden, der nur den Retriever/Store isoliert
 * gemockt hat. Hier wird die echte Repository-Filterung serverseitig
 * ausgefuehrt (SQLite + echtes Schema), sodass ein Datenleck zwischen
 * Tenants zu einem Test-Fehler fuehrt.
 *
 * Der EmbeddingService wird durch eine deterministische Hash-basierte
 * Implementierung ersetzt, damit keine echten Mistral-API-Calls
 * entstehen und die Aehnlichkeitsberechnung kontrolliert verlaeuft.
 */
final class RagTenantIsolationIntegrationTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private EmbeddingRepository $embeddingRepository;
    private VectorStore $vectorStore;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->embeddingRepository = static::getContainer()->get(EmbeddingRepository::class);
        $this->vectorStore = static::getContainer()->get(VectorStore::class);
        $this->ensureSchema();
        $this->clearEmbeddings();
    }

    protected function tearDown(): void
    {
        try {
            $this->clearEmbeddings();
            $this->entityManager->clear();
        } catch (\Throwable) {
            // Cleanup-Best-Effort.
        }
        parent::tearDown();
    }

    /**
     * Tenant A speichert "A ist CEO von X"; Tenant B fragt inhaltlich
     * passend nach. Assertion: der System-Prompt von Tenant B enthaelt
     * den Inhalt von Tenant A NICHT.
     */
    public function testTenantBCannotRetrieveContextFromTenantA(): void
    {
        // Tenant A: sensibler Kontext, der nicht zu Tenant B durchsickern darf.
        $this->vectorStore->store(
            'A ist CEO von X und verdient 500k.',
            'knowledge',
            'tenant-a-profile',
            ['user_identifier' => 'tenant-a'],
        );

        // Tenant B fragt inhaltlich passend nach (gleiche Tokens fuer
        // deterministische Aehnlichkeit ueber den Hash-Embedding-Stub).
        $contextInjector = static::getContainer()->get(ContextInjector::class);
        $userContextB = $this->userContextFor('tenant-b');

        $messageBag = new MessageBag(Message::ofUser('Wer ist CEO von X?'));
        $input = new Input('mistral-small-latest', $messageBag);

        // UserContext fuer Tenant B am Request setzen, damit der
        // ContextInjector den Identifier ausliest (P0-1 Verdrahtung).
        $userContextB->setUserIdentifier('tenant-b');

        $contextInjector->processInput($input);

        $systemContent = $this->extractSystemContent($input->getMessageBag());

        // Kern-Assertion: Tenant A's sensibler Inhalt darf fuer Tenant B
        // nicht im injizierten Kontext auftauchen.
        self::assertStringNotContainsString(
            'A ist CEO von X',
            $systemContent,
            'RAG-Kontext von Tenant A wurde in Tenant B injiziert (Tenant-Isolation verletzt).',
        );
    }

    /**
     * Tenant A fragt seinen eigenen Kontext ab. Assertion: der
     * System-Prompt enthaelt den Inhalt von Tenant A.
     */
    public function testTenantARetrievesOwnContext(): void
    {
        $this->vectorStore->store(
            'A ist CEO von X und verdient 500k.',
            'knowledge',
            'tenant-a-profile',
            ['user_identifier' => 'tenant-a'],
        );

        $contextInjector = static::getContainer()->get(ContextInjector::class);
        $userContextA = $this->userContextFor('tenant-a');
        $userContextA->setUserIdentifier('tenant-a');

        $messageBag = new MessageBag(Message::ofUser('Wer ist CEO von X?'));
        $input = new Input('mistral-small-latest', $messageBag);

        $contextInjector->processInput($input);

        $systemContent = $this->extractSystemContent($input->getMessageBag());
        self::assertStringContainsString('A ist CEO von X', $systemContent);
    }

    /**
     * Systemweites Wissen (ohne user_identifier) ist fuer jeden Tenant
     * sichtbar (z. B. globale Tool-Beschreibungen).
     */
    public function testSystemWideContextIsVisibleToAllTenants(): void
    {
        // Query und gespeicherter Kontext teilen viele Tokens, sodass die
        // deterministische Embedding-Aehnlichkeit >= 0.5 (Default) liegt.
        $this->vectorStore->store(
            'EVIE nutzt Symfony AI und ist eine Plattform.',
            'knowledge',
            'system-docs',
            [], // kein user_identifier -> systemweites Wissen
        );

        $contextInjector = static::getContainer()->get(ContextInjector::class);
        $userContextB = $this->userContextFor('tenant-b');
        $userContextB->setUserIdentifier('tenant-b');

        $messageBag = new MessageBag(Message::ofUser('Was ist die EVIE Plattform und Symfony AI?'));
        $input = new Input('mistral-small-latest', $messageBag);

        $contextInjector->processInput($input);

        $systemContent = $this->extractSystemContent($input->getMessageBag());
        self::assertStringContainsString('Symfony AI', $systemContent);
    }

    private function ensureSchema(): void
    {
        $schemaTool = new SchemaTool($this->entityManager);
        $classes = $this->entityManager->getMetadataFactory()->getAllMetadata();
        try {
            $schemaTool->createSchema($classes);
        } catch (\Throwable) {
            // Schema existiert moeglicherweise bereits.
        }
    }

    private function clearEmbeddings(): void
    {
        $this->entityManager->createQueryBuilder()
            ->delete(Embedding::class, 'e')
            ->getQuery()->execute();
    }

    private function userContextFor(string $identifier): UserContext
    {
        // UserContext liest den Identifier primär aus dem Security-Token
        // (TokenStorage). Im Test gibt es keinen Auth-Flow, deshalb setzen
        // wir einen echten UsernamePasswordToken mit einem InMemoryUser,
        // dessen getUserIdentifier() den Tenant liefert. Zusätzlich wird
        // der RequestStack gepusht, damit auch der Fallback-Pfad greift.
        $tokenStorage = static::getContainer()->get(TokenStorageInterface::class);
        $user = new InMemoryUser($identifier, 'password');
        $token = new UsernamePasswordToken($user, 'test', ['ROLE_USER']);
        $tokenStorage->setToken($token);

        $requestStack = static::getContainer()->get(RequestStack::class);
        $request = new Request();
        $request->attributes->set('_evie_user_identifier', $identifier);
        $requestStack->push($request);

        return static::getContainer()->get(UserContext::class);
    }

    private function extractSystemContent(MessageBag $messageBag): string
    {
        $content = '';
        foreach ($messageBag->getMessages() as $message) {
            // Der ContextInjector fuegt eine SystemMessage hinzu. Wir extrahieren
            // den Text aller System-Nachrichten, die den Kontext-Marker tragen.
            if (Role::System !== $message->getRole()) {
                continue;
            }
            $raw = $message->getContent();
            $text = is_string($raw) ? $raw : '';
            if (str_contains($text, 'Relevanter Kontext aus der Wissensbasis')) {
                $content .= $text;
            }
        }

        return $content;
    }
}
