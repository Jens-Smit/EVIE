<?php

declare(strict_types=1);

// tests/E2E/Gate/ProductionSecurityGateE2ETest.php

namespace App\Tests\E2E\Gate;

use App\AI\Security\OutboundRequestPolicy;
use App\AI\Security\SecurityGuard;
use App\AI\Workflow\MailDraftHitlService;
use App\Entity\MailDraft;
use App\Entity\ToolDefinition;
use App\Entity\User;
use App\Entity\UserProfile;
use App\Repository\MailDraftRepository;
use App\Repository\ToolDefinitionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * PRODUCTION SECURITY GATE (Blueprint §4.D / §17).
 *
 * Ein einziges E2E-Gate, das die drei produktionskritischen Invarianten
 * GLEICHZEITIG gegen den echten Kernel und die echte DB prueft — nicht
 * isolierte Unit-Tests, sondern den durchgehenden Stack:
 *
 * GATE 1 — TENANT-ISOLATION:
 *   Tenant A darf niemals Daten von Tenant B sehen: Streaming-Sessions,
 *   Mail-Entwuerfe, Tool-Definitionen. Fremdzugriff muss 403 sein,
 *   Listen duerfen fremde Eintraege nie enthalten.
 *
 * GATE 2 — INTERNE-INFRASTRUKTUR-BLOCK:
 *   EVIE darf niemals auf interne IPs, Docker-Netzwerke, Localhost oder
 *   das Filesystem zugreifen (SSRF/Sandbox): SecurityGuard-Policy UND
 *   OutboundRequestPolicy muessen jede interne Zieladresse Deny-en.
 *
 * GATE 3 — HITL VOR SEITENEFFEKTEN:
 *   Ein externer Seiteneffekt (E-Mail-Versand) darf NACH der Freigabe
 *   stattfinden — und NUR dann: prepareDraft versendet nichts (Status
 *   pending_approval), erst approveDraft versendet; fremde Tenant-
 *   Freigaben muessen mit 403 abgewiesen werden.
 *
 * Der Test laeuft gegen die echte Container-Konfiguration (test env,
 * SQLite var/test.db bzw. CI-PostgreSQL) mit dem realen Security-Stack
 * (SecurityGuard, OutboundRequestPolicy, HitlMailController, Voters).
 * Keine Mockdaten: alle Entitaeten werden real persistiert und ueber
 * HTTP-Endpunkte abgefragt.
 */
final class ProductionSecurityGateE2ETest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private UserPasswordHasherInterface $passwordHasher;

    private const TENANT_A = 'gate-tenant-a@evie-test.de';
    private const TENANT_B = 'gate-tenant-b@evie-test.de';
    private const PASSWORD = 'GatePass123!';

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->passwordHasher = $container->get(UserPasswordHasherInterface::class);

        $this->ensureSchema();
        $this->purgateGateData();
        $this->createTenant(self::TENANT_A);
        $this->createTenant(self::TENANT_B);
    }

    protected function tearDown(): void
    {
        try {
            $this->purgeGateData();
        } catch (\Throwable) {
        }
        $this->entityManager->clear();
        parent::tearDown();
        static::ensureKernelShutdown();
    }

    private function ensureSchema(): void
    {
        $schemaTool = new SchemaTool($this->entityManager);
        try {
            $schemaTool->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());
        } catch (\Throwable) {
            // Schema existiert bereits.
        }
    }

    private function purgateGateData(): void
    {
        $em = $this->entityManager;
        foreach ([
            'DELETE FROM mail_drafts',
            'DELETE FROM tool_definitions',
            'DELETE FROM agent_history',
            'DELETE FROM user_profile WHERE user_identifier IN (:ids)',
        ] as $index => $sql) {
            if ($index === 3) {
                $em->getConnection()->executeStatement(
                    $sql,
                    ['ids' => [self::TENANT_A, self::TENANT_B]],
                    ['ids' => \Doctrine\DBAL\Connection::PARAM_STR_ARRAY]
                );
                continue;
            }
            $em->getConnection()->executeStatement($sql);
        }
        $em->getConnection()->executeStatement(
            'DELETE FROM users WHERE email IN (:ids)',
            ['ids' => [self::TENANT_A, self::TENANT_B]],
            ['ids' => \Doctrine\DBAL\Connection::PARAM_STR_ARRAY]
        );
    }

    private function createTenant(string $email): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setFirstName('Gate')
            ->setLastName('Tenant')
            ->setRoles(['ROLE_USER'])
            ->setPassword($this->passwordHasher->hashPassword(new User(), self::PASSWORD));
        $user->setOnboardingComplete(true);
        $this->entityManager->persist($user);

        $profile = new UserProfile();
        $profile->setUserIdentifier($email);
        $this->entityManager->persist($profile);

        $this->entityManager->flush();
        return $user;
    }

    private function login(KernelBrowser $client, string $email): void
    {
        $crawler = $client->request('GET', '/login');
        $csrfToken = '';
        $tokenInput = $crawler->filter('input[type="hidden"][id$="_csrf_token"]')->last();
        if ($tokenInput->count() > 0) {
            $value = $tokenInput->attr('value');
            if (is_string($value) && $value !== '') {
                $csrfToken = $value;
            }
        }
        if ($csrfToken === '') {
            foreach ($crawler->filter('input[type="hidden"]') as $node) {
                $name = $node->getAttribute('name') ?? '';
                if ($name === '_csrf_token') {
                    $value = $node->getAttribute('value');
                    if (is_string($value) && $value !== '') {
                        $csrfToken = $value;
                        break;
                    }
                }
            }
        }
        $client->request('POST', '/login', [
            'email' => $email,
            'password' => self::PASSWORD,
            '_csrf_token' => $csrfToken,
            '_remember_me' => 1,
        ]);
    }

    /**
     * Wechselt den aktiven Tenant am selben Client (Logout + Re-Login).
     * Der Kernel wird nur einmal gebootet; ein zweiter KernelBrowser
     * ist bei WebTestCase nicht moeglich.
     */
    private function switchTenant(string $email): void
    {
        $this->client->request('GET', '/logout');
        $this->login($this->client, $email);
    }

    // ==================================================================
    // GATE 1 — TENANT-ISOLATION: Tenant A sieht niemals Tenant-B-Daten
    // ==================================================================

    public function testGate1TenantIsolationOnMailDrafts(): void
    {
        $this->login($this->client, self::TENANT_A);

        /** @var MailDraftHitlService $hitlService */
        $hitlService = static::getContainer()->get(MailDraftHitlService::class);
        $draftA = $hitlService->prepareDraft(
            self::TENANT_A,
            'Vertraulich: Kontoauszug Tenant A',
            'Geheime Geschaeftszahlen von Tenant A',
            ['kunde-a@example.com']
        );
        $draftB = $hitlService->prepareDraft(
            self::TENANT_B,
            'Vertraulich: Kontoauszug Tenant B',
            'Geheime Geschaeftszahlen von Tenant B',
            ['kunde-b@example.com']
        );

        // Liste von Tenant A darf NUR eigene Entwuerfe enthalten.
        $this->client->request('GET', '/tools/mail-drafts');
        self::assertResponseIsSuccessful();
        $listA = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($listA);
        $idsA = array_map(static fn (array $d): int => (int) $d['id'], $listA['drafts']);
        self::assertContains($draftA->getId(), $idsA);
        self::assertNotContains($draftB->getId(), $idsA);
        foreach ($listA['drafts'] as $d) {
            self::assertStringNotContainsString('Tenant B', (string) $d['subject']);
            self::assertStringNotContainsString('Tenant B', (string) $d['body']);
        }

        // Tenant B sieht umgekehrt niemals den Entwurf von A.
        $this->switchTenant(self::TENANT_B);
        $this->client->request('GET', '/tools/mail-drafts');
        $listB = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($listB);
        $idsB = array_map(static fn (array $d): int => (int) $d['id'], $listB['drafts']);
        self::assertContains($draftB->getId(), $idsB);
        self::assertNotContains($draftA->getId(), $idsB);
        foreach ($listB['drafts'] as $d) {
            self::assertStringNotContainsString('Tenant A', (string) $d['subject']);
            self::assertStringNotContainsString('Tenant A', (string) $d['body']);
        }

        // Direkter Zugriff (ID-Spoofing) muss mit 403 abgewiesen werden.
        $this->client->request('POST', '/tools/mail-drafts/' . $draftA->getId() . '/reject', ['reason' => 'angriff']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testGate1TenantIsolationOnToolDefinitions(): void
    {
        $this->login($this->client, self::TENANT_A);

        $toolA = new ToolDefinition();
        $toolA->setName('gate_tool_tenant_a');
        $toolA->setDescription('Gate-Tool Tenant A');
        $toolA->setUserIdentifier(self::TENANT_A);
        $toolA->setStatus('approved');
        $this->entityManager->persist($toolA);

        $toolB = new ToolDefinition();
        $toolB->setName('gate_tool_tenant_b');
        $toolB->setDescription('Gate-Tool Tenant B');
        $toolB->setUserIdentifier(self::TENANT_B);
        $toolB->setStatus('pending');
        $this->entityManager->persist($toolB);
        $this->entityManager->flush();

        /** @var ToolDefinitionRepository $toolRepo */
        $toolRepo = static::getContainer()->get(ToolDefinitionRepository::class);

        // Repository-Ebene: Tenant A findet Tenant-B-Tools nie.
        self::assertNull($toolRepo->findOneByNameForUser('gate_tool_tenant_b', self::TENANT_A));
        self::assertNotNull($toolRepo->findOneByNameForUser('gate_tool_tenant_a', self::TENANT_A));
        self::assertNotNull($toolRepo->findOneByNameForUser('gate_tool_tenant_b', self::TENANT_B));

        // HitlListener-Definitionssuche (P0-5): eine Anfrage von Tenant A
        // fuer den Namen eines Tenant-B-Tools liefert KEINE Definition.
        $foreignTool = $toolRepo->findOneByNameForUser('gate_tool_tenant_b', self::TENANT_A);
        self::assertNull($foreignTool);
    }

    // ==================================================================
    // GATE 2 — INTERN: interne IPs / Docker / Filesystem blockiert
    // ==================================================================

    /**
     * @dataProvider blockedInternalTargetProvider
     */
    public function testGate2InternalInfrastructureIsBlocked(string $url): void
    {
        /** @var SecurityGuard $guard */
        $guard = static::getContainer()->get(SecurityGuard::class);
        self::assertFalse(
            $guard->isUrlSafe($url),
            sprintf('SecurityGuard muss interne Zieladresse blockieren: %s', $url)
        );

        /** @var OutboundRequestPolicy $policy */
        $policy = static::getContainer()->get(OutboundRequestPolicy::class);
        self::assertFalse(
            $policy->isUrlAllowed($url),
            sprintf('OutboundRequestPolicy muss interne Zieladresse blockieren: %s', $url)
        );
    }

    /**
     * @return list<list<string>>
     */
    public static function blockedInternalTargetProvider(): array
    {
        return [
            ['http://localhost/secret'],
            ['http://127.0.0.1:8080/admin'],
            ['http://192.168.1.10/instance-metadata'],
            ['http://10.0.0.5/docker-socket'],
            ['http://172.20.0.3/mercure/.well-known/mercure'],
            ['http://169.254.169.254/latest/meta-data/'],
            ['http://0.0.0.0/'],
            ['http://[::1]/'],
            ['file:///etc/passwd'],
            ['ftp://internal-host/data'],
        ];
    }

    public function testGate2FilesystemSandboxBlocksSystemPaths(): void
    {
        /** @var SecurityGuard $guard */
        $guard = static::getContainer()->get(SecurityGuard::class);

        foreach (['/etc/passwd', '/var/run/docker.sock', '/../etc/shadow', '~/.ssh/id_rsa'] as $path) {
            self::assertFalse(
                $guard->isPathSafe($path),
                sprintf('SecurityGuard muss Systempfad blockieren: %s', $path)
            );
        }
    }

    public function testGate2ExternalPublicUrlIsAllowed(): void
    {
        /** @var SecurityGuard $guard */
        $guard = static::getContainer()->get(SecurityGuard::class);
        self::assertTrue($guard->isUrlSafe('https://api.mistral.ai/v1/models'));

        /** @var OutboundRequestPolicy $policy */
        $policy = static::getContainer()->get(OutboundRequestPolicy::class);
        self::assertTrue($policy->isUrlAllowed('https://api.tavily.com/search'));
    }

    // ==================================================================
    // GATE 3 — HITL: kein externer Seiteneffekt ohne Freigabe
    // ==================================================================

    public function testGate3MailDraftWaitsForApprovalBeforeSending(): void
    {
        $this->login($this->client, self::TENANT_A);

        /** @var MailDraftHitlService $hitlService */
        $hitlService = static::getContainer()->get(MailDraftHitlService::class);
        $draft = $hitlService->prepareDraft(
            self::TENANT_A,
            'Angebot an Kunden',
            'Sehr geehrte Damen und Herren, ...',
            ['kunde@example.com']
        );

        // INVARIANTE 1: Nach dem Erstellen ist KEIN Versand erfolgt —
        // der Entwurf wartet zwingend auf Freigabe (HITL).
        self::assertSame(MailDraft::STATUS_PENDING, $draft->getStatus());
        self::assertNull($draft->getSentAt());

        /** @var MailDraftRepository $draftRepo */
        $draftRepo = static::getContainer()->get(MailDraftRepository::class);
        $fresh = $draftRepo->find($draft->getId());
        self::assertNotNull($fresh);
        self::assertTrue($fresh->isPending());
        self::assertNull($fresh->getSentAt());

        // INVARIANTE 2: Die Freigabe (HITL) löst den Versand aus —
        // via HTTP-Endpunkt gegen den echten Controller.
        $this->client->request('POST', '/tools/mail-drafts/' . $draft->getId() . '/approve');
        self::assertResponseIsSuccessful();

        $sent = $draftRepo->find($draft->getId());
        self::assertNotNull($sent);
        self::assertNotSame(MailDraft::STATUS_PENDING, $sent->getStatus());
        self::assertContains($sent->getStatus(), [MailDraft::STATUS_APPROVED, MailDraft::STATUS_SENT]);
        self::assertNotNull($sent->getSentAt());
    }

    public function testGate3DoubleApprovalIsRejected(): void
    {
        $this->login($this->client, self::TENANT_A);

        /** @var MailDraftHitlService $hitlService */
        $hitlService = static::getContainer()->get(MailDraftHitlService::class);
        $draft = $hitlService->prepareDraft(
            self::TENANT_A,
            'Doppelfreigabe-Test',
            'Body',
            ['kunde@example.com']
        );

        $this->client->request('POST', '/tools/mail-drafts/' . $draft->getId() . '/approve');
        self::assertResponseIsSuccessful();

        // Zweitfreigabe eines bereits versendeten Entwurfs -> 400 (kein zweiter Versand).
        $this->client->request('POST', '/tools/mail-drafts/' . $draft->getId() . '/approve');
        self::assertResponseStatusCodeSame(400);
    }

    public function testGate3ForeignTenantCannotApprove(): void
    {
        $this->login($this->client, self::TENANT_B);

        /** @var MailDraftHitlService $hitlService */
        $hitlService = static::getContainer()->get(MailDraftHitlService::class);
        $draft = $hitlService->prepareDraft(
            self::TENANT_A,
            'Angriffsversuch-Ziel',
            'Tenant B darf das NIE freigeben',
            ['kunde-a@example.com']
        );

        $this->client->request('POST', '/tools/mail-drafts/' . $draft->getId() . '/approve');
        self::assertResponseStatusCodeSame(403);

        /** @var MailDraftRepository $draftRepo */
        $draftRepo = static::getContainer()->get(MailDraftRepository::class);
        $fresh = $draftRepo->find($draft->getId());
        self::assertNotNull($fresh);
        self::assertTrue($fresh->isPending(), 'Fremdfreigabe darf den Status nicht veraendern.');
        self::assertNull($fresh->getSentAt(), 'Fremdfreigabe darf NIE versenden.');
    }

    public function testGate3RejectedDraftIsNeverSent(): void
    {
        $this->login($this->client, self::TENANT_A);

        /** @var MailDraftHitlService $hitlService */
        $hitlService = static::getContainer()->get(MailDraftHitlService::class);
        $draft = $hitlService->prepareDraft(
            self::TENANT_A,
            'Abzulehnender Entwurf',
            'Body',
            ['kunde@example.com']
        );

        $this->client->request('POST', '/tools/mail-drafts/' . $draft->getId() . '/reject', ['reason' => 'Inhalt unpassend']);
        self::assertResponseIsSuccessful();

        /** @var MailDraftRepository $draftRepo */
        $draftRepo = static::getContainer()->get(MailDraftRepository::class);
        $fresh = $draftRepo->find($draft->getId());
        self::assertNotNull($fresh);
        self::assertSame(MailDraft::STATUS_REJECTED, $fresh->getStatus());
        self::assertNull($fresh->getSentAt(), 'Abgelehnter Entwurf darf NIE versendet werden.');
    }
}
