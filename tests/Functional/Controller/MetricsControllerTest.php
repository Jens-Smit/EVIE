<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

/**
 * Functional-Tests fuer MetricsController (P0-9 Observability).
 *
 * MetricsController war laut Coverage-Report ungetestet (0%). Dieser Test
 * deckt alle Admin-Endpunkte ab: index, token-usage, latency,
 * tool-success-rate, audit. Prueft Auth (403 fuer Nicht-Admins) und
 * Erfolgsfaelle (leere DB -> gueltige JSON-Struktur).
 */
class MetricsControllerTest extends AbstractFunctionalControllerTest
{
    public function testMetricsIndexRequiresAdmin(): void
    {
        $this->createUserAndLogin('metrics-user@test.de', 'MetricsPass123');
        $this->client->request('GET', '/api/metrics');

        self::assertResponseStatusCodeSame(403);
    }

    public function testMetricsIndexRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/metrics');

        self::assertResponseStatusCodeSame(401);
    }

    public function testMetricsIndexReturnsSuccessForAdmin(): void
    {
        $this->createUserAndLogin('metrics-admin@test.de', 'MetricsPass123', ['ROLE_ADMIN']);
        $this->client->request('GET', '/api/metrics');

        self::assertResponseIsSuccessful();
        self::assertJson($this->client->getResponse()->getContent());
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('success', $data['status']);
        self::assertArrayHasKey('data', $data);
        self::assertArrayHasKey('timestamp', $data);
    }

    public function testTokenUsageMetricsReturnsDataForAdmin(): void
    {
        $this->createUserAndLogin('metrics-token@test.de', 'MetricsPass123', ['ROLE_ADMIN']);
        $this->client->request('GET', '/api/metrics/token-usage');

        self::assertResponseIsSuccessful();
        self::assertJson($this->client->getResponse()->getContent());
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('success', $data['status']);
        self::assertArrayHasKey('metrics', $data);
    }

    public function testLatencyMetricsReturnsDataForAdmin(): void
    {
        $this->createUserAndLogin('metrics-latency@test.de', 'MetricsPass123', ['ROLE_ADMIN']);
        $this->client->request('GET', '/api/metrics/latency');

        self::assertResponseIsSuccessful();
        self::assertJson($this->client->getResponse()->getContent());
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('success', $data['status']);
        self::assertArrayHasKey('metrics', $data);
    }

    public function testToolSuccessRateMetricsReturnsDataForAdmin(): void
    {
        $this->createUserAndLogin('metrics-rate@test.de', 'MetricsPass123', ['ROLE_ADMIN']);
        $this->client->request('GET', '/api/metrics/tool-success-rate');

        self::assertResponseIsSuccessful();
        self::assertJson($this->client->getResponse()->getContent());
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('success', $data['status']);
        self::assertArrayHasKey('metrics', $data);
    }

    public function testAuditMetricsReturnsDataForAdmin(): void
    {
        $this->createUserAndLogin('metrics-audit@test.de', 'MetricsPass123', ['ROLE_ADMIN']);
        $this->client->request('GET', '/api/metrics/audit');

        self::assertResponseIsSuccessful();
        self::assertJson($this->client->getResponse()->getContent());
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('success', $data['status']);
        self::assertArrayHasKey('metrics', $data);
    }
}
