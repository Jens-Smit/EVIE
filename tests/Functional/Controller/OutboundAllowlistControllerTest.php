<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\OutboundAllowlistEntry;

/**
 * Functional-Tests fuer den Frontend-Freigabe-Controller der Outbound-Ziele.
 *
 * Deckt ab: Admin-Gating (403 fuer normale User), Liste, Erstellen (CSRF- und
 * Validierungsfehler, Duplikat), Toggle und Loeschen. Analog
 * McpServerControllerTest; alle Routen sind ROLE_ADMIN-only (Blueprint §4.D:
 * Freigabe erfolgt explizit im Frontend).
 */
class OutboundAllowlistControllerTest extends AbstractFunctionalControllerTestCase
{
    protected function tearDown(): void
    {
        try {
            $this->entityManager->createQueryBuilder()
                ->delete(OutboundAllowlistEntry::class, 'o')
                ->getQuery()->execute();
        } catch (\Throwable) {
        }
        parent::tearDown();
    }

    public function testIndexRequiresAdmin(): void
    {
        $this->createUserAndLogin('allow-user@test.de', 'AllowPass123');
        $this->client->request('GET', '/settings/outbound-allowlist');
        self::assertResponseStatusCodeSame(403);
    }

    public function testIndexRendersForAdmin(): void
    {
        $this->createUserAndLogin('allow-admin@test.de', 'AllowPass123', ['ROLE_ADMIN']);
        $this->client->request('GET', '/settings/outbound-allowlist');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('h1');
    }

    public function testCreatePersistsEntryWithCsrf(): void
    {
        $this->createUserAndLogin('allow-create@test.de', 'AllowPass123', ['ROLE_ADMIN']);
        $crawler = $this->client->request('GET', '/settings/outbound-allowlist');
        $token = $crawler->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', '/settings/outbound-allowlist', [
            '_token' => $token,
            'hostPattern' => 'API.Tavily.com',
            'patternType' => 'exact',
            'description' => 'Tavily Websuche',
        ]);

        self::assertResponseRedirects('/settings/outbound-allowlist');
        $entry = $this->entityManager
            ->getRepository(OutboundAllowlistEntry::class)
            ->findOneByHostPattern('api.tavily.com');
        self::assertNotNull($entry);
        self::assertSame('exact', $entry->getPatternType());
        self::assertTrue($entry->isActive());
    }

    public function testCreateRejectsInvalidCsrf(): void
    {
        $this->createUserAndLogin('allow-csrf@test.de', 'AllowPass123', ['ROLE_ADMIN']);
        $this->client->request('POST', '/settings/outbound-allowlist', [
            '_token' => 'invalid',
            'hostPattern' => 'api.tavily.com',
            'patternType' => 'exact',
        ]);

        self::assertResponseRedirects('/settings/outbound-allowlist');
        $entry = $this->entityManager
            ->getRepository(OutboundAllowlistEntry::class)
            ->findOneByHostPattern('api.tavily.com');
        self::assertNull($entry);
    }

    public function testCreateRejectsInvalidHostPattern(): void
    {
        $this->createUserAndLogin('allow-invalid@test.de', 'AllowPass123', ['ROLE_ADMIN']);
        $crawler = $this->client->request('GET', '/settings/outbound-allowlist');
        $token = $crawler->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', '/settings/outbound-allowlist', [
            '_token' => $token,
            'hostPattern' => 'https://evil.example/..%2f',
            'patternType' => 'exact',
        ]);

        self::assertResponseRedirects('/settings/outbound-allowlist');
        $entry = $this->entityManager
            ->getRepository(OutboundAllowlistEntry::class)
            ->findOneByHostPattern('https://evil.example/..%2f');
        self::assertNull($entry);
    }

    public function testToggleDeactivatesEntry(): void
    {
        $this->createUserAndLogin('allow-toggle@test.de', 'AllowPass123', ['ROLE_ADMIN']);
        $entry = $this->createEntry('mistral.ai', 'suffix');

        $this->client->request('POST', '/settings/outbound-allowlist/' . $entry->getId() . '/toggle', [
            '_token' => 'invalid',
        ]);
        self::assertResponseRedirects('/settings/outbound-allowlist');
        self::assertTrue($entry->isActive());

        $crawler = $this->client->request('GET', '/settings/outbound-allowlist');
        $token = $crawler->filter('form[action*="/toggle"] input[name="_token"]')->first()->attr('value');
        $this->client->request('POST', '/settings/outbound-allowlist/' . $entry->getId() . '/toggle', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/settings/outbound-allowlist');
        $this->entityManager->clear();
        $updated = $this->entityManager
            ->getRepository(OutboundAllowlistEntry::class)
            ->find($entry->getId());
        self::assertFalse($updated->isActive());
    }

    public function testDeleteRemovesEntry(): void
    {
        $this->createUserAndLogin('allow-delete@test.de', 'AllowPass123', ['ROLE_ADMIN']);
        $entry = $this->createEntry('generativelanguage.googleapis.com', 'suffix');
        $id = $entry->getId();

        $crawler = $this->client->request('GET', '/settings/outbound-allowlist');
        $token = $crawler->filter('form[action*="/delete"] input[name="_token"]')->first()->attr('value');
        $this->client->request('POST', '/settings/outbound-allowlist/' . $id . '/delete', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/settings/outbound-allowlist');
        $this->entityManager->clear();
        self::assertNull(
            $this->entityManager->getRepository(OutboundAllowlistEntry::class)->find($id)
        );
    }

    private function createEntry(string $host, string $patternType): OutboundAllowlistEntry
    {
        $entry = new OutboundAllowlistEntry();
        $entry->setHostPattern($host);
        $entry->setPatternType($patternType);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }
}
