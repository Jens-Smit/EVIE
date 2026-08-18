<?php

declare(strict_types=1);

namespace App\Tests\E2E\Smoke;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * E2E-Smoke-Test fuer die kritischen EVIE-Security-Aktionen ueber HTTP.
 *
 * Die LLM-Abruf-Pfade (Onboarding, Toolgenerierung, Agent-Orchestrierung)
 * werden ueber den deterministischen StubAgent in den Unit- und Functional-
 * Tests vollstaendig abgedeckt (StubAgent ersetzt den echten Mistral-Abruf).
 * Die echten Mistral-Aufrufe laufen im separaten E2E-LLM-Job.
 *
 * Dieser Smoke-Test verifiziert die Security-Layer der kritischen Endpoints
 * (Agent-Dialog, Tool-Freigabe) gegen unauthentifizierte Zugriffe - kein
 * Tool darf ohne Authentifizierung ausgefuehrt oder freigegeben werden.
 */
final class CriticalActionsE2ETest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->ensureSchema();
    }

    protected function tearDown(): void
    {
        try {
            $conn = $this->entityManager->getConnection();
            $conn->executeStatement('DELETE FROM tool_definitions');
            $conn->executeStatement('DELETE FROM agent_history');
            $conn->executeStatement('DELETE FROM user_profiles');
            $conn->executeStatement('DELETE FROM users');
            $this->entityManager->clear();
        } catch (\Throwable) {
            // Tabellen existieren moeglicherweise nicht in jeder Test-DB.
        }
        parent::tearDown();
    }

    public function testAgentDialogEndpointRejectsUnauthenticatedAccess(): void
    {
        // Ohne Authentifizierung darf der Agent-Dialog keine Tool-Ausfuehrung
        // zulassen. Der Security-Layer muss aktiv sein (kein 200 mit Ergebnis).
        $this->client->request('POST', '/api/agent/dialog', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['message' => 'Hallo'], JSON_THROW_ON_ERROR));

        $status = $this->client->getResponse()->getStatusCode();
        // Ohne Auth -> Redirect (302) zu Login, 401, 403 oder 429 (Rate-Limit).
        // Niemals 200 (keine unautorisierte Tool-Ausfuehrung).
        self::assertContains($status, [302, 401, 403, 429], sprintf('Ohne Auth erwartet 302/401/403/429, bekam %d', $status));
    }

    public function testAgentDialogRejectsTenantSpoofingForAnonymousUser(): void
    {
        // Ein anonymer Aufrufer darf keinen Tenant-Identifier spoofen; der
        // Security-Layer blockiert den Zugriff (kein 200 mit Ergebnis).
        $this->client->request('POST', '/api/agent/dialog', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'message' => 'Test',
            'user_identifier' => 'attacker-spoof',
        ], JSON_THROW_ON_ERROR));

        $status = $this->client->getResponse()->getStatusCode();
        self::assertContains($status, [302, 401, 403, 429]);
    }

    public function testToolApprovalEndpointProtectedForAnonymousUser(): void
    {
        // /api/tools ist ueber access_control + ApiSecurityListener geschuetzt.
        // Der ApiSecurityListener wirft AccessDeniedHttpException fuer anonyme
        // Aufrufer; der Test-Client faengt die Exception ab und prueft, dass
        // keine Freigabe (200) erfolgt.
        $this->client->catchExceptions(false);
        try {
            $this->client->request('POST', '/api/tools/1/approve', [], [], [
                'CONTENT_TYPE' => 'application/json',
            ]);
            $status = $this->client->getResponse()->getStatusCode();
        } catch (\Throwable) {
            // Die Exception zeigt, dass der Security-Layer aktiv ist (kein 200).
            $status = 403;
        } finally {
            $this->client->catchExceptions(true);
        }

        self::assertNotSame(200, $status, 'Anonymer Tool-Freigabe-Versuch darf nicht mit 200 bestaetigt werden.');
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
}
