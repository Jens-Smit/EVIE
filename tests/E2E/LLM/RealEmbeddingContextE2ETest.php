<?php

declare(strict_types=1);

namespace App\Tests\E2E\LLM;

use App\AI\Onboarding\OnboardingFlowManager;
use App\AI\Rag\EmbeddingServiceInterface;
use App\AI\Rag\Retriever;
use App\AI\Rag\VectorStore;
use App\Repository\EmbeddingRepository;
use App\Service\SecretService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * E2E-LLM-Test: echte Mistral-Chat- und EMBEDDING-Aufrufe mit dem in den
 * GitHub-Secrets hinterlegten Key (CI-Job e2e-llm, EVIE_LLM_E2E=1).
 *
 * Im Gegensatz zu RealLlmFlowTest prueft dieser Test gezielt den KONTEXT-
 * BEHALT: Der Key wird wie im Onboarding als pro-Tenant-Secret in der DB
 * hinterlegt (verschluesselt) und die Aufrufe laufen ueber die TenantAware-
 * Decorators, die den Key aus der DB aufloesen. Embeddings muessen echte,
 * nicht-null Vektoren liefern, damit die RAG-Kontextsuche funktioniert.
 *
 * LLM-Aufrufe sind minimiert: jeder Test macht nur die noetigen Abrufe.
 * Ohne MISTRAL_API_KEY werden die Tests skipped (kein Fehlschlag).
 */
final class RealEmbeddingContextE2ETest extends KernelTestCase
{
    private const USER = 'e2e-llm-embedding-user';
    private const MISSION = 'Ich moechte EVIE fuer die Vertriebsunterstuetzung einer Softwarefirma nutzen.';

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();
        if (!$this->hasMistralKey()) {
            self::markTestSkipped('MISTRAL_API_KEY nicht gesetzt - E2E-LLM-Tests werden skipped.');
        }
        self::bootKernel();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->ensureSchema();
    }

    protected function tearDown(): void
    {
        if ($this->hasMistralKey()) {
            $this->cleanup();
        }
        parent::tearDown();
    }

    /**
     * Echtes Mistral-Embedding: kein Null-Vektor, korrekte Dimension.
     * Deckt den 400-Bad-Request-Fehler aus den Logs ab (Null-Vektoren in
     * der DB -> keine Kontextsuche moeglich).
     */
    public function testRealMistralEmbeddingReturnsNonNullVector(): void
    {
        $embeddingService = static::getContainer()->get(EmbeddingServiceInterface::class);
        $vector = $embeddingService->embedText('Vertrieb und Lead-Generierung fuer Softwarefirmen');

        self::assertNotEmpty($vector, 'Embedding darf kein Null-/Leervektor sein (Mistral-Embeddings-Aufruf fehlgeschlagen).');
        $nonZero = 0;
        foreach ($vector as $value) {
            self::assertIsFloat($value);
            if (abs($value) > 0.000001) {
                $nonZero++;
            }
        }
        self::assertGreaterThan(0, $nonZero, 'Embedding-Vektor darf nicht ausschliesslich aus Nullen bestehen.');
        self::assertSame(1024, count($vector), 'mistral-embed liefert 1024-dimensionale Vektoren.');
    }

    /**
     * Kontextsuche mit echten Embeddings: Profil-Kontext speichern und
     * semantisch wiederfinden. Verifiziert, dass der Chat den Kontext
     * behaelt (RAG: store -> embed(query) -> retrieve).
     */
    public function testRealEmbeddingRetrievalFindsStoredContext(): void
    {
        $vectorStore = static::getContainer()->get(VectorStore::class);
        $retriever = static::getContainer()->get(Retriever::class);

        // 1 echter Embedding-Abruf beim Store (plus Cache-Lookup) ...
        $vectorStore->store(
            'Kunde EVIE-Testcontext: Der Nutzer arbeitet in der Vertriebsabteilung einer Softwarefirma.',
            'user_profile',
            'e2e_llm_embedding_user',
            ['user_identifier' => self::USER, 'trust_level' => 'system']
        );

        // ... und 1 echter Embedding-Abruf fuer die Query.
        $result = $retriever->retrieve(
            'In welcher Abteilung arbeitet der Nutzer?',
            ['user_identifier' => self::USER]
        );

        self::assertTrue($result->hasResults(), 'Die Kontextsuche muss den gespeicherten Profil-Kontext finden.');
        $found = false;
        foreach ($result->getItems() as $item) {
            if (str_contains($item->getContent(), 'Vertriebsabteilung')) {
                $found = true;
                break;
            }
        }
        self::assertTrue($found, 'Der abgelegte Vertriebs-Kontext muss semantisch gefunden werden.');
    }

    /**
     * Onboarding-Chat mit echtem Mistral-Call: extrahierte Angaben landen im
     * Onboarding-Kontext (DB) und eine zweite Runde sieht denselben Kontext
     * (Kontext-Behalt ueber Requests hinweg).
     */
    public function testRealOnboardingChatIngestsAndKeepsContextAcrossRounds(): void
    {
        $secretService = static::getContainer()->get(SecretService::class);
        // Simuliert Phase A: der im Onboarding hinterlegte (echte) Key wird
        // verschluesselt als Tenant-Secret gespeichert.
        $secretService->set('MISTRAL_API_KEY', $this->mistralKey(), self::USER, 'onboarding');

        $manager = static::getContainer()->get(OnboardingFlowManager::class);
        $manager->startOnboarding(self::USER);

        // Runde 1: offene Aufgabenbeschreibung -> 1 echter LLM-Abruf.
        $roundOne = $manager->chat(self::USER, self::MISSION);
        self::assertNotSame('', trim($roundOne['response']), 'Der Onboarding-Chat muss eine echte Antwort liefern.');

        // Die extrahierten Erkenntnisse muessen im DB-Kontext persistiert
        // sein (mission_statement ist Pflichtfeld der Phase B).
        $status = $manager->getOnboardingStatus(self::USER);
        self::assertSame('in_progress', $status['status']);
        $data = $status['onboarding_data'] ?? [];
        self::assertSame(
            self::MISSION,
            $data['mission_statement'] ?? null,
            'Die Chat-Extraktion muss mission_statement im Onboarding-Kontext persistieren.'
        );

        // Runde 2: Rueckfrage im selben Kontext -> wieder 1 echter Abruf.
        // Der System-Prompt serialisiert onboarding_data; der Agent kennt
        // die Mission aus Runde 1 (Kontext-Behalt).
        $roundTwo = $manager->chat(self::USER, 'Kannst du meine Angaben kurz zusammenfassen?');
        self::assertNotSame('', trim($roundTwo['response']));
    }

    /**
     * Tenant-Isolation der Kontextsuche mit echten Embeddings: Kontext von
     * Tenant A darf fuer Tenant B nicht auffindbar sein.
     */
    public function testRealEmbeddingRetrievalIsTenantIsolated(): void
    {
        $vectorStore = static::getContainer()->get(VectorStore::class);
        $retriever = static::getContainer()->get(Retriever::class);
        $otherTenant = 'e2e-llm-other-tenant';

        $vectorStore->store(
            'Tenant-A-Geheimkontext: Interner Vertriebsplan der Softwarefirma.',
            'knowledge',
            'e2e_llm_tenant_isolation_a',
            ['user_identifier' => self::USER]
        );

        $crossResult = $retriever->retrieve(
            'Interner Vertriebsplan der Softwarefirma',
            ['user_identifier' => $otherTenant]
        );
        foreach ($crossResult->getItems() as $item) {
            self::assertStringNotContainsString(
                'Tenant-A-Geheimkontext',
                $item->getContent(),
                'Tenant B darf keinen Kontext von Tenant A empfangen (P0-1).'
            );
        }
    }

    private function hasMistralKey(): bool
    {
        $key = $_ENV['MISTRAL_API_KEY'] ?? (getenv('MISTRAL_API_KEY') ?: '');

        return \is_string($key) && $key !== '' && $key !== 'test_mistral_api_key' && $key !== 'test';
    }

    private function mistralKey(): string
    {
        return $_ENV['MISTRAL_API_KEY'] ?? (string) getenv('MISTRAL_API_KEY');
    }

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

    private function cleanup(): void
    {
        try {
            $embeddingRepo = static::getContainer()->get(EmbeddingRepository::class);
            $embeddingRepo->createQueryBuilder('e')
                ->delete()
                ->where("e.source LIKE :src")
                ->setParameter('src', 'e2e_llm%')
                ->getQuery()
                ->execute();
            $this->entityManager->createQueryBuilder()
                ->delete(\App\Entity\UserProfile::class, 'p')
                ->where('p.userIdentifier = :uid')
                ->setParameter('uid', self::USER)
                ->getQuery()
                ->execute();
            $this->entityManager->createQueryBuilder()
                ->delete(\App\Entity\Secret::class, 's')
                ->where('s.userIdentifier = :uid')
                ->setParameter('uid', self::USER)
                ->getQuery()
                ->execute();
            $this->entityManager->clear();
        } catch (\Throwable) {
            // Cleanup ist best effort.
        }
    }
}
