<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\AuditLog;

/**
 * Functional-Tests fuer AuditLogController.
 *
 * AuditLogController war laut Coverage-Report ungetestet (0%). Dieser Test
 * deckt die Admin-Endpunkte ab: HTML-Liste, API-Liste, Show (HTML + API),
 * Filter, Statistics und CSV-Export. Prueft Auth (403/Redirect) und
 * Erfolgsfaelle inkl. eines echten AuditLog-Datensatzes.
 */
class AuditLogControllerTest extends AbstractFunctionalControllerTest
{
    protected function tearDown(): void
    {
        try {
            $this->entityManager->createQueryBuilder()
                ->delete(AuditLog::class, 'a')
                ->getQuery()->execute();
        } catch (\Throwable) {
        }
        parent::tearDown();
    }

    private function createAuditLog(): AuditLog
    {
        $log = new AuditLog();
        $log->setAction('tool_execution');
        $log->setEntityType('ToolDefinition');
        $log->setEntityId(42);
        $log->setStatus('success');
        $log->setDetails('Test-Audit-Eintrag');
        $this->entityManager->persist($log);
        $this->entityManager->flush();
        return $log;
    }

    public function testListAuditLogsRequiresAdmin(): void
    {
        $this->createUserAndLogin('audit-user@test.de', 'AuditPass123');
        $this->client->request('GET', '/audit-logs');

        self::assertResponseStatusCodeSame(403);
    }

    public function testListAuditLogsRendersForAdmin(): void
    {
        $this->createUserAndLogin('audit-admin@test.de', 'AuditPass123', ['ROLE_ADMIN']);
        $this->createAuditLog();
        $this->client->request('GET', '/audit-logs');

        self::assertResponseIsSuccessful();
    }

    public function testApiListAuditLogsReturnsSuccess(): void
    {
        $this->createUserAndLogin('audit-api@test.de', 'AuditPass123', ['ROLE_ADMIN']);
        $log = $this->createAuditLog();
        $this->client->request('GET', '/api/audit-logs');

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('success', $data['status']);
        self::assertGreaterThanOrEqual(1, $data['pagination']['total']);
        $ids = array_column($data['data'], 'id');
        self::assertContains($log->getId(), $ids);
    }

    public function testApiListAuditLogsAppliesFilters(): void
    {
        $this->createUserAndLogin('audit-filter@test.de', 'AuditPass123', ['ROLE_ADMIN']);
        $log = $this->createAuditLog();
        $this->client->request('GET', '/api/audit-logs?action=tool_execution&entity_type=ToolDefinition&status=success&page=1&limit=10');

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('success', $data['status']);
        $ids = array_column($data['data'], 'id');
        self::assertContains($log->getId(), $ids);
    }

    public function testShowAuditLogHtmlReturns404ForUnknownId(): void
    {
        $this->createUserAndLogin('audit-show@test.de', 'AuditPass123', ['ROLE_ADMIN']);
        $this->client->request('GET', '/audit-logs/999999');

        self::assertResponseStatusCodeSame(404);
    }

    public function testShowAuditLogHtmlRendersForAdmin(): void
    {
        $this->createUserAndLogin('audit-show2@test.de', 'AuditPass123', ['ROLE_ADMIN']);
        $log = $this->createAuditLog();
        $this->client->request('GET', '/audit-logs/' . $log->getId());

        self::assertResponseIsSuccessful();
    }

    public function testApiShowAuditLogReturnsData(): void
    {
        $this->createUserAndLogin('audit-apishow@test.de', 'AuditPass123', ['ROLE_ADMIN']);
        $log = $this->createAuditLog();
        $this->client->request('GET', '/api/audit-logs/' . $log->getId());

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('success', $data['status']);
        self::assertSame($log->getId(), $data['data']['id']);
        self::assertSame('tool_execution', $data['data']['action']);
    }

    public function testApiShowAuditLogReturns404ForUnknownId(): void
    {
        $this->createUserAndLogin('audit-api404@test.de', 'AuditPass123', ['ROLE_ADMIN']);
        $this->client->request('GET', '/api/audit-logs/999999');

        self::assertResponseStatusCodeSame(404);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('error', $data);
    }

    public function testFilterAuditLogsReturnsData(): void
    {
        $this->createUserAndLogin('audit-flt@test.de', 'AuditPass123', ['ROLE_ADMIN']);
        $log = $this->createAuditLog();
        $this->client->request('GET', '/api/audit-logs/filter?action=tool_execution&entity_type=ToolDefinition&status=success');

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('success', $data['status']);
        $ids = array_column($data['data'], 'id');
        self::assertContains($log->getId(), $ids);
    }

    public function testAuditLogsStatisticsReturnsData(): void
    {
        $this->createUserAndLogin('audit-stats@test.de', 'AuditPass123', ['ROLE_ADMIN']);
        $this->createAuditLog();
        $this->client->request('GET', '/api/audit-logs/statistics');

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('success', $data['status']);
        self::assertGreaterThanOrEqual(1, $data['total']);
        self::assertArrayHasKey('statistics', $data);
        self::assertArrayHasKey('actions', $data['statistics']);
    }

    public function testExportAuditLogsReturnsCsv(): void
    {
        $this->createUserAndLogin('audit-export@test.de', 'AuditPass123', ['ROLE_ADMIN']);
        $this->createAuditLog();
        $this->client->request('GET', '/audit-logs/export');

        self::assertResponseIsSuccessful();
        $contentType = $this->client->getResponse()->headers->get('Content-Type');
        self::assertStringContainsString('text/csv', (string) $contentType);
        self::assertStringContainsString('tool_execution', (string) $this->client->getResponse()->getContent());
    }
}
