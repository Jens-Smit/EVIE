<?php

declare(strict_types=1);

namespace App\Tests\E2E\Smoke;

use App\AI\Streaming\StreamingSessionManager;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\Grant;
use Symfony\Component\Mercure\Jwt\LcobucciFactory;
use Symfony\Component\Mercure\Update;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * E2E-Smoke-Tests mit LAUFENDEM Mercure-Hub (dunglas/mercure v0.24.x).
 *
 * Diese Suite schliesst die Luecke zwischen den bisherigen Stub-basierten
 * Tests (NullMercureHub) und dem produktiven Betrieb: Sie beweist, dass
 * Streaming-Updates vom Backend ueber den echten Mercure-Hub publiziert
 * und von einem SSE-Subscriber (wie das Frontend via EventSource)
 * empfangen werden.
 *
 * Voraussetzungen (Guard, sonst Skip mit Anleitung):
 *  - EVIE_MERCURE_E2E=1 (aktiviert RealMercurePass: echter Hub statt Stub)
 *  - erreichbarer Hub unter MERCURE_URL (CI: dunglas/mercure-Service,
 *    lokal: docker compose up mercure)
 *
 * Getestete Ketten:
 *  1. Hub->publish() -> SSE-Subscriber (Payload unveraendert, wie Frontend)
 *  2. POST /api/streaming/sessions -> Messenger (sync) -> Handler-Kette
 *     (Start/Chunk/End-Handler) -> StreamingPublisher -> Hub -> Subscriber
 *  3. Frontend-Regression: show.html.twig rendert fuer laufende Sessions
 *     Hub-URL + Topic + EventSource (Status-Bug: 'active' vs. 'running').
 */
class MercureStreamingE2ETest extends WebTestCase
{
    private const SKIP_MESSAGE = 'Mercure-E2E-Tests benoetigen einen laufenden '
        . 'Mercure-Hub: EVIE_MERCURE_E2E=1 setzen und den Hub starten '
        . '(CI: e2e-mercure-Job, lokal: docker compose up mercure). '
        . 'Ohne Flag laeuft die Suite absichtlich nicht gegen den NullMercureHub-Stub.';

    private const DEFAULT_HUB_URL = 'http://127.0.0.1:3000/.well-known/mercure';
    private const DEFAULT_JWT_SECRET = 'e2e-mercure-test-secret-not-for-prod-0123456789';

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private HttpClientInterface $subscriberClient;
    private string $hubUrl;
    private string $jwtSecret;

    public static function setUpBeforeClass(): void
    {
        if (!self::isFlagEnabled('EVIE_MERCURE_E2E')) {
            self::markTestSkipped(self::SKIP_MESSAGE);
        }

        if (!self::isHubReachable()) {
            self::markTestSkipped(
                'Mercure-Hub ist unter MERCURE_URL nicht erreichbar. '
                . 'Hub starten (docker compose up mercure bzw. CI-Service-Container) '
                . 'und MERCURE_URL/MERCURE_JWT_SECRET setzen.'
            );
        }

        parent::setUpBeforeClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->ensureSchema();
        $this->purgeStreamingTables();

        $this->hubUrl = self::env('MERCURE_URL', self::DEFAULT_HUB_URL);
        $this->jwtSecret = self::env('MERCURE_JWT_SECRET', self::DEFAULT_JWT_SECRET);
        $this->subscriberClient = HttpClient::create(['timeout' => 10]);
    }

    protected function tearDown(): void
    {
        $this->purgeStreamingTables();
        unset($this->subscriberClient, $this->client);
        parent::tearDown();
    }

    /**
     * Kette 1: Ein direkt an den Hub publiziertes Update erreicht den
     * SSE-Subscriber unveraendert — dieselbe Sicht wie das Frontend.
     */
    public function testPublishUpdateIsReceivedBySubscriber(): void
    {
        $sessionId = 'e2e-publish-' . bin2hex(random_bytes(6));
        $topic = '/streaming/sessions/' . $sessionId;
        $payload = [
            'event' => 'progress',
            'data' => ['percentage' => 42.5, 'message' => 'Verarbeite Daten'],
            'timestamp' => (new \DateTimeImmutable())->format('c'),
        ];

        $hub = static::getContainer()->get(HubInterface::class);
        self::assertNotInstanceOf(\App\Tests\Stub\NullMercureHub::class, $hub, sprintf(
            'HubInterface muss im Mercure-E2E-Modus der echte Hub sein (evtl. TraceableHub-dekoriert), %s erhalten. '
            . 'EVIE_MERCURE_E2E=1 muss beim Kernel-Boot gesetzt gewesen sein.',
            get_debug_type($hub)
        ));

        $subscriber = $this->startSubscriber($topic);
        usleep(150000);

        $hub->publish(new Update($topic, json_encode($payload, JSON_THROW_ON_ERROR)));

        $events = $this->collectStreamEvents($subscriber, fn (array $e) => 'progress' === $e['type']);

        self::assertCount(1, $events, 'Genau ein progress-Event muss beim Subscriber ankommen.');
        self::assertSame(
            json_encode($payload, JSON_THROW_ON_ERROR),
            $events[0]['raw'],
            'Payload muss unveraendert beim Subscriber ankommen.'
        );
    }

    /**
     * Kette 2 (Frontend-Sicht): POST /api/streaming/sessions durchlaeuft
     * die komplette Backend-Kette: Session anlegen -> Messenger (sync) ->
     * ExecuteToolMessageHandler -> Start-/Chunk-/End-Handler ->
     * StreamingPublisher -> Hub. Der Subscriber muss die Events der
     * Session empfangen; das (nicht registrierte) Tool fuehrt zum
     * error-Chunk plus session_end mit success=false.
     */
    public function testApiSessionStreamIsReceivedBySubscriber(): void
    {
        $this->createUserAndLogin('mercure-e2e@test.de', 'MercureE2EPass123');

        // Subscribe auf das Topic-Template, bevor die Session-ID bekannt
        // ist (URI-Template-Selector der Mercure-0.x-Spezifikation).
        $subscriber = $this->startSubscriber('/streaming/sessions/{sessionId}');
        usleep(150000);

        $this->client->request('POST', '/api/streaming/sessions', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'tool_name' => 'e2e_smoke_tool',
            'arguments' => ['input' => 'mercure-e2e'],
        ], JSON_THROW_ON_ERROR));

        $response = $this->client->getResponse();
        self::assertSame(202, $response->getStatusCode(), 'Session-Erstellung muss 202 liefern.');
        $body = json_decode($response->getContent(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('session_id', $body);

        $events = $this->collectStreamEvents($subscriber, fn (array $e) => 'session_end' === $e['type']);
        $types = array_map(fn (array $e) => $e['type'], $events);

        self::assertContains('session_start', $types, 'session_start muss gestreamt werden.');
        self::assertContains('error', $types, 'Das Tool-Ergebnis (Tool nicht registriert) muss als error gestreamt werden.');
        self::assertContains('session_end', $types, 'session_end muss gestreamt werden.');

        $sessionStart = self::findEvent($events, 'session_start');
        self::assertSame('e2e_smoke_tool', $sessionStart['payload']['data']['tool_name'] ?? null);
        self::assertSame($body['session_id'], $sessionStart['payload']['data']['session_id'] ?? null);

        $error = self::findEvent($events, 'error');
        self::assertSame($body['session_id'], $error['payload']['data']['session_id'] ?? null, 'Session-ID muss zum API-Response passen.');
        self::assertSame('e2e_smoke_tool', $error['payload']['data']['tool_name'] ?? null);
        self::assertArrayHasKey('error', $error['payload']['data']['chunk'] ?? [], 'Der error-Chunk muss die Fehlermeldung enthalten.');

        $sessionEnd = self::findEvent($events, 'session_end');
        self::assertFalse($sessionEnd['payload']['data']['success'] ?? null, 'session_end muss success=false melden.');
        self::assertSame('failed', $sessionEnd['payload']['data']['final_status'] ?? null);
    }

    /**
     * Kette 3 (Frontend-Regression): Die Streaming-Detailseite muss fuer
     * eine laufende Session die Hub-URL (mercure()-Twig-Function), das
     * Topic und den EventSource-Aufruf rendern.
     */
    public function testStreamingShowPageRendersHubUrlForActiveSession(): void
    {
        $user = $this->createUserAndLogin('mercure-e2e-page@test.de', 'MercureE2EPass123');

        $sessionManager = static::getContainer()->get(StreamingSessionManager::class);
        $session = $sessionManager->createSession('e2e_smoke_tool', ['input' => 'x'], $user->getUserIdentifier());
        $sessionManager->startSession($session->getSessionId());

        $crawler = $this->client->request('GET', '/streaming/sessions/' . $session->getSessionId());

        self::assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();

        self::assertSame(1, preg_match('/const\s+hubUrl\s*=\s*("(?:\\\\.|[^"\\\\])*")\s*;/', $content, $hubUrlMatch), 'Die Seite muss die per mercure() gerenderte Hub-URL als JS-Konstante enthalten.');
        $hubUrl = json_decode($hubUrlMatch[1]);
        self::assertIsString($hubUrl);
        self::assertStringContainsString('.well-known/mercure', $hubUrl, 'Die Hub-URL muss den Mercure-Endpoint enthalten.');
        self::assertStringContainsString('topic=' . rawurlencode('/streaming/sessions/' . $session->getSessionId()), $hubUrl, 'Die Hub-URL muss das Session-Topic als Abfrage-Parameter enthalten.');

        self::assertStringContainsString('/streaming/sessions/' . $session->getSessionId(), $content, 'Das Session-Topic muss im Markup enthalten sein.');
        self::assertStringContainsString('new EventSource', $content, 'EventSource muss fuer laufende Sessions instantiiert werden.');
        self::assertStringContainsString("!['running', 'pending'].includes('running')", $content, 'Der Live-Feed-Guard muss running-Sessions durchlassen (Regression: frueher wurde auf den nie gesetzten Status active geprueft).');
        self::assertSame('running', $crawler->filter('#session-status-value')->text(), 'Der gerenderte Status muss running lauten.');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private static function isFlagEnabled(string $name): bool
    {
        return \in_array(self::env($name, ''), ['1', 'true', 'yes'], true);
    }

    private static function env(string $name, string $default): string
    {
        $value = $_ENV[$name] ?? getenv($name);

        return (false === $value || '' === $value) ? $default : (string) $value;
    }

    private static function isHubReachable(): bool
    {
        $url = self::env('MERCURE_URL', self::DEFAULT_HUB_URL) . '?topic=/ping';
        $context = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);

        // Jede HTTP-Antwort (auch 401 "Subscriber-JWT required") beweist, dass
        // der Hub laeuft; nur ohne Antwort (Connection refused) ist er nicht
        // erreichbar. ignore_errors + $http_response_header liefert auch fuer
        // 4xx-Status einen Header, ohne Exception-Overhead.
        @file_get_contents($url, false, $context);

        return isset($http_response_header) && \is_array($http_response_header);
    }

    /**
     * Oeffnet einen SSE-Subscribe-Stream (wie EventSource im Browser).
     * Der Subscriber-JWT wird mitgesendet, damit der Test gegen den
     * produktiven Hub (ohne anonymes Subscribe) funktioniert.
     */
    private function startSubscriber(string $topic): ResponseInterface
    {
        $response = $this->subscriberClient->request('GET', $this->hubUrl, [
            'query' => ['topic' => $topic],
            'auth_bearer' => $this->createSubscriberJwt(),
            'buffer' => false,
        ]);

        // Symfony HttpClient sendet den Request lazy: Ohne diesen Zugriff
        // wuerde die Subscribe-Verbindung erst beim ersten stream()-Aufruf
        // aufgebaut und fruehere Updates verpasst.
        $response->getStatusCode();

        return $response;
    }

    private function createSubscriberJwt(): string
    {
        $factory = new LcobucciFactory($this->jwtSecret);

        return $factory->create([
            new Grant([Grant::ACTION_SUBSCRIBE], ['*']),
        ]);
    }

    /**
     * Liest den SSE-Stream und sammelt die JSON-Payloads, bis $stop wahr
     * liefert (Zeitbudget, da der Stream offen bleibt).
     *
     * @return list<array{type: string, raw: string, payload: array}>
     */
    private function collectStreamEvents(ResponseInterface $response, \Closure $stop, int $timeoutMs = 5000): array
    {
        $events = [];
        $deadline = microtime(true) + ($timeoutMs / 1000);
        $buffer = '';

        while (microtime(true) < $deadline) {
            try {
                foreach ($this->subscriberClient->stream($response, 0.2) as $chunk) {
                    try {
                        if (!$chunk->isLast()) {
                            $buffer .= $chunk->getContent();
                        }
                    } catch (\Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface) {
                        // Idle-Timeout dieser Iteration: weiterpollen, der
                        // SSE-Stream bleibt offen und wirft beim Pollen
                        // regelmoessig TimeoutExceptions.
                    }
                }
            } catch (\Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface) {
                // Weiterpollen bis zum Zeitbudget bzw. Stop-Event.
            } catch (\Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface) {
                break;
            }

            while (false !== ($pos = strpos($buffer, "\n\n"))) {
                $rawEvent = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 2);
                $event = $this->parseStreamEvent($rawEvent);

                if (null === $event) {
                    continue;
                }

                $events[] = $event;

                if ($stop($event)) {
                    return $events;
                }
            }
        }

        return $events;
    }

    /**
     * @return array{type: string, raw: string, payload: array}|null
     */
    private function parseStreamEvent(string $raw): ?array
    {
        $data = '';

        foreach (explode("\n", $raw) as $line) {
            if (str_starts_with($line, 'data:')) {
                $data .= ltrim(substr($line, 5), ' ');
            }
        }

        if ('' === $data) {
            return null;
        }

        $payload = json_decode($data, true);

        if (!\is_array($payload)) {
            return null;
        }

        return [
            'type' => (string) ($payload['event'] ?? 'data'),
            'raw' => $data,
            'payload' => $payload,
        ];
    }

    /**
     * @param list<array{type: string, raw: string, payload: array}> $events
     */
    private static function findEvent(array $events, string $type): array
    {
        foreach ($events as $event) {
            if ($event['type'] === $type) {
                return $event;
            }
        }

        self::fail(sprintf('Event %s wurde nicht empfangen.', $type));
    }

    private function createUserAndLogin(string $email, string $plainPassword): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = (new User())
            ->setEmail($email)
            ->setFirstName('Mercure')
            ->setLastName('E2E')
            ->setPassword($hasher->hashPassword(new User(), $plainPassword))
            ->setOnboardingComplete(true);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/login');
        $csrfToken = $this->extractCsrfToken($crawler);

        $this->client->request('POST', '/login', [
            'email' => $email,
            'password' => $plainPassword,
            '_csrf_token' => $csrfToken,
            '_remember_me' => 1,
        ]);
        $this->client->followRedirect();

        return $user;
    }

    private function extractCsrfToken(Crawler $crawler): string
    {
        $tokenInput = $crawler->filter('input[type="hidden"][id$="_csrf_token"]')->last();

        if ($tokenInput->count() > 0) {
            $value = $tokenInput->attr('value');

            if (\is_string($value) && '' !== $value) {
                return $value;
            }
        }

        foreach ($crawler->filter('input[type="hidden"]') as $node) {
            $name = $node->getAttribute('name') ?? '';

            if (str_ends_with($name, '[_csrf_token]') || '_csrf_token' === $name) {
                $value = $node->getAttribute('value');

                if (\is_string($value) && '' !== $value) {
                    return $value;
                }
            }
        }

        return static::getContainer()
            ->get('security.csrf.token_manager')
            ->getToken('authenticate')
            ->getValue();
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

    private function purgeStreamingTables(): void
    {
        $conn = $this->entityManager->getConnection();

        foreach ([
            'DELETE FROM ai_streaming_sessions',
            'DELETE FROM reset_password_request',
            'DELETE FROM users',
        ] as $statement) {
            try {
                $conn->executeStatement($statement);
            } catch (\Throwable) {
                // Tabelle existiert moeglicherweise nicht in jeder Test-DB.
            }
        }

        $this->entityManager->clear();
    }
}
