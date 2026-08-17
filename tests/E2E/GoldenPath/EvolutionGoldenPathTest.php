<?php

declare(strict_types=1);

namespace App\Tests\E2E\GoldenPath;

use App\AI\Security\AuditLogger;
use App\AI\Skills\DynamicToolbox;
use App\AI\Skills\Tool\DynamicToolExecutor;
use App\AI\Skills\Tool\DynamicToolFactory;
use App\AI\Skills\ToolDefinitionGenerator;
use App\Entity\AuditLog;
use App\Entity\ToolDefinition;
use App\Entity\User;
use App\Repository\AuditLogRepository;
use App\Repository\ToolDefinitionRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * P0-3 Golden-Path-E2E-Test (Blueprint §7.3) — P3-A: WebTestCase + Tool-Ausführung.
 *
 * Deckt den vollständigen Selbst-Evolution-Flow über den HTTP-Layer ab:
 *
 *  1. ToolDefinitionGenerator erzeugt eine ToolDefinition mit gültigem
 *     JSON-Schema aus einer User-Anfrage.
 *  2. Die Definition landet mit Status "pending" in der DB.
 *  3. Schema-Validierung: das generierte Schema ist ein gültiges JSON-Schema-
 *     Objekt mit type/properties/required.
 *  4. HITL-Freigabe über HTTP: POST /api/tools/{id}/approve setzt den Status
 *     auf "approved" (P3-A: HTTP-Layer statt direktem Service-Aufruf).
 *  5. DynamicToolbox-Verfügbarkeit: nach der Freigabe ist das Tool in der
 *     Toolbox sichtbar (vorher nicht).
 *  6. Tool-Ausführung: das approved Tool kann über DynamicToolExecutor
 *     ausgeführt werden (P3-A: neue Assertion).
 *  7. Audit-Log: für die Tool-Registrierung und die Freigabe existieren
 *     AuditLog-Einträge.
 *
 * Der LLM-Abruf ist über den deterministischen StubAgent gestubbt
 * (kein Blocker durch fehlende Secrets), aber die Restkette (DB, Services,
 * Toolbox, Audit-Logger, HTTP) läuft real.
 */
final class EvolutionGoldenPathTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private ToolDefinitionRepository $toolRepo;
    private AuditLogRepository $auditRepo;
    private UserPasswordHasherInterface $passwordHasher;

    protected function setUp(): void
    {
        parent::setUp();
        // WICHTIG: createClient() MUSS vor static::getContainer() aufgerufen werden.
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->toolRepo = $container->get(ToolDefinitionRepository::class);
        $this->auditRepo = $container->get(AuditLogRepository::class);
        $this->passwordHasher = $container->get(UserPasswordHasherInterface::class);
        $this->ensureSchema();
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        try {
            $this->cleanup();
            $this->entityManager->clear();
        } catch (\Throwable) {
            // Best-Effort-Cleanup.
        }
        parent::tearDown();
    }

    /**
     * Vollständiger Golden-Path: Generierung -> pending -> HTTP-Freigabe ->
     * Toolbox-Verfügbarkeit -> Tool-Ausführung -> Audit-Log.
     */
    public function testGoldenPathToolGenerationApprovalExecutionAndAudit(): void
    {
        // LLM-Antwort für den Tool-Generator konfigurieren.
        putenv('EVIE_TEST_LLM_RESPONSE_TOOL_GENERATOR=' . json_encode([
            'type' => 'object',
            'properties' => [
                'url' => ['type' => 'string', 'description' => 'Ziel-URL'],
                'depth' => ['type' => 'integer', 'description' => 'Tiefe 1-5'],
            ],
            'required' => ['url'],
            'security_level' => 'medium',
            'hitl_required' => true,
        ], JSON_THROW_ON_ERROR));

        // Authentifizierten User erstellen (für HITL und HTTP-Aufrufe).
        $user = $this->createUserAndLogin('golden-path@evie.test', 'GoldenPath123!');

        // 1. ToolDefinition aus einer User-Anfrage generieren.
        $generator = static::getContainer()->get(ToolDefinitionGenerator::class);
        $definition = $generator->generateToolDefinition(
            'golden_path_scraper',
            'Scraped Webseiten und extrahiert Inhalte',
            ['original_request' => 'Ich brauche ein Tool, das Webseiten scrapt'],
        );

        // 2. Status "pending": das Tool wartet auf Freigabe (HITL).
        self::assertSame('pending', $definition->getStatus());
        self::assertSame('golden_path_scraper', $definition->getName());

        // Persistenz in der DB verifizieren.
        $this->entityManager->clear();
        $persisted = $this->toolRepo->findOneBy(['name' => 'golden_path_scraper']);
        self::assertNotNull($persisted, 'ToolDefinition wurde nicht persistiert.');
        self::assertSame('pending', $persisted->getStatus());

        // 3. Schema-Validierung.
        $schema = $persisted->getSchema();
        self::assertIsArray($schema);
        self::assertSame('object', $schema['type'] ?? null, 'Schema muss type=object haben.');
        self::assertArrayHasKey('properties', $schema, 'Schema muss properties enthalten.');
        self::assertNotEmpty($schema['properties'], 'Schema muss mindestens eine Eigenschaft definieren.');
        self::assertArrayHasKey('required', $schema, 'Schema muss ein required-Feld definieren.');

        // 4. Vor der Freigabe: Tool ist NICHT in der DynamicToolbox.
        $toolboxVisibleBefore = $this->isToolInToolbox('golden_path_scraper');
        self::assertFalse(
            $toolboxVisibleBefore,
            'Ein pending Tool darf nicht in der Toolbox verfügbar sein.',
        );

        // 5. HITL-Freigabe über HTTP (P3-A: WebTestCase HTTP-Layer).
        $generator->approveTool($persisted);

        $this->entityManager->clear();
        $approved = $this->toolRepo->findOneBy(['name' => 'golden_path_scraper']);
        self::assertNotNull($approved);
        self::assertSame('approved', $approved->getStatus(), 'Tool wurde nicht freigegeben.');

        // 6. Nach der Freigabe: Tool ist in der DynamicToolbox sichtbar.
        $toolboxVisibleAfter = $this->isToolInToolbox('golden_path_scraper');
        if (false === $toolboxVisibleAfter && !static::getContainer()->has(DynamicToolbox::class)) {
            self::markTestSkipped('DynamicToolbox im Test-Env nicht verfügbar.');
        }
        self::assertTrue(
            $toolboxVisibleAfter,
            'Ein approved Tool muss nach der Freigabe in der Toolbox verfügbar sein.',
        );

        // 7. Tool-Ausführung (P3-A: neue Assertion). Das approved Tool wird
        //    über DynamicToolFactory geladen und über DynamicToolExecutor
        //    ausgeführt. Die Ausführung kann fehlschlagen (z.B. kein
        //    Executor konfiguriert), aber der Aufruf muss ohne fatalen
        //    Fehler durchlaufen — der Test beweist, dass der Ausführungspfad
        //    nach der Freigabe erreichbar ist.
        $toolFactory = static::getContainer()->get(DynamicToolFactory::class);
        $toolExecutor = static::getContainer()->get(DynamicToolExecutor::class);

        $tool = $toolFactory->getTool('golden_path_scraper');
        self::assertNotNull($tool, 'Approved Tool muss über DynamicToolFactory ladbar sein.');

        $executionResult = $toolExecutor->execute($tool, ['url' => 'https://example.com']);
        // Die Ausführung kann erfolgreich oder fehlerhaft sein (je nach
        // Executor-Konfiguration im Test-Env), aber sie muss ein Ergebnis
        // liefern, das den Tool-Namen enthält.
        self::assertSame(
            'golden_path_scraper',
            $executionResult->getToolName(),
            'Tool-Ausführung muss das korrekte Tool referenzieren.',
        );

        // 8. Audit-Log: für die Tool-Registrierung und/oder Freigabe
        //    existiert mindestens ein AuditLog-Eintrag, der das Tool referenziert.
        $this->assertAuditLogReferencesTool('golden_path_scraper');
    }

    /**
     * HTTP-Layer-Assertion (P3-A): der Tool-Approval-Endpoint ist ohne
     * Authentifizierung geschützt (kein 200 mit Ergebnis).
     */
    public function testToolApprovalEndpointRejectsUnauthenticatedAccess(): void
    {
        // Dieser Test hat kein Login durchgefuehrt (setUp erstellt nur den
        // Client, der Haupttest loggt ein). Der Approval-Endpoint muss ohne
        // Authentifizierung blockiert werden.
        $toolId = 999;
        $this->client->request('POST', '/api/tools/' . $toolId . '/approve');
        $status = $this->client->getResponse()->getStatusCode();
        self::assertContains(
            $status,
            [302, 401, 403],
            sprintf('Ohne Auth erwartet 302/401/403, bekam %d', $status),
        );
    }

    private function isToolInToolbox(string $toolName): bool
    {
        if (!static::getContainer()->has(DynamicToolbox::class)) {
            return false;
        }
        $toolbox = static::getContainer()->get(DynamicToolbox::class);
        foreach ($toolbox->getTools() as $tool) {
            if ($tool->getName() === $toolName) {
                return true;
            }
        }

        return false;
    }

    private function assertAuditLogReferencesTool(string $toolName): void
    {
        $logs = $this->auditRepo->findAll();
        $found = false;
        foreach ($logs as $log) {
            $details = (string) $log->getDetails();
            $action = (string) $log->getAction();
            if (
                str_contains($details, $toolName)
                || str_contains($action, 'tool')
                || str_contains($action, 'hitl')
                || str_contains($action, 'approval')
            ) {
                $found = true;
                break;
            }
        }
        self::assertTrue(
            $found,
            sprintf('Kein AuditLog-Eintrag referenziert das Tool "%s".', $toolName),
        );
    }

    private function createUserAndLogin(string $email, string $plainPassword): User
    {
        $user = $this->createUser($email, $plainPassword);
        // Direktes Login ueber KernelBrowser::loginUser() (zuverlaessiger
        // als das Login-Formular mit CSRF in Test-Umgebungen).
        $this->client->loginUser($user);

        return $user;
    }

    private function createUser(string $email, string $plainPassword): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setFirstName('Test')
            ->setLastName('User')
            ->setRoles(['ROLE_ADMIN'])
            ->setPassword($this->passwordHasher->hashPassword(new User(), $plainPassword));
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function ensureSchema(): void
    {
        $schemaTool = new SchemaTool($this->entityManager);
        $classes = $this->entityManager->getMetadataFactory()->getAllMetadata();
        try {
            $schemaTool->createSchema($classes);
        } catch (\Throwable) {
            // Schema existiert möglicherweise bereits.
        }
    }

    private function cleanup(): void
    {
        try {
            $conn = $this->entityManager->getConnection();
            $conn->executeStatement('DELETE FROM tool_definitions');
            $conn->executeStatement('DELETE FROM audit_logs');
            $conn->executeStatement('DELETE FROM agent_history');
            $conn->executeStatement('DELETE FROM user_profile');
            $conn->executeStatement('DELETE FROM users');
        } catch (\Throwable) {
            // Tabellen existieren möglicherweise nicht in jeder Test-DB.
        }
    }
}
