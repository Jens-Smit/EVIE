<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\AI\Streaming\StreamingSessionManager;
use App\Entity\StreamingSession;
use App\Entity\User;

/**
 * Functional-Tests fuer StreamingController (API-Endpunkte).
 *
 * StreamingController war laut Coverage-Report ungetestet (0%). Deckt
 * create/status/list/active/cancel/delete/stats/cleanup ab inkl.
 * Ownership-Pruefung (403) und 404-Faellen.
 */
class StreamingControllerTest extends AbstractFunctionalControllerTest
{
    private StreamingSessionManager $sessionManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sessionManager = static::getContainer()->get(StreamingSessionManager::class);
    }

    protected function tearDown(): void
    {
        try {
            $this->entityManager->createQueryBuilder()
                ->delete(StreamingSession::class, 's')
                ->getQuery()->execute();
        } catch (\Throwable) {
        }
        parent::tearDown();
    }

    private function createSessionForUser(User $user, string $toolName = 'test_tool'): StreamingSession
    {
        return $this->sessionManager->createSession(
            $toolName,
            ['arg' => 'value'],
            $user->getUserIdentifier()
        );
    }

    public function testCreateSessionRequiresAuthentication(): void
    {
        $this->client->request('POST', '/api/streaming/sessions', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['tool_name' => 'tool', 'arguments' => []]));

        self::assertResponseRedirects('/login');
    }

    public function testCreateSessionReturnsCreated(): void
    {
        $user = $this->createUserAndLogin('stream-create@test.de', 'StreamPass123');
        $this->client->request('POST', '/api/streaming/sessions', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['tool_name' => 'test_tool', 'arguments' => ['x' => 1]]));

        self::assertResponseStatusCodeSame(202);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('created', $data['status']);
        self::assertNotEmpty($data['session_id']);
        self::assertSame('test_tool', $data['tool_name']);
    }

    public function testCreateSessionWithInvalidJsonReturns400(): void
    {
        $this->createUserAndLogin('stream-invalid@test.de', 'StreamPass123');
        $this->client->request('POST', '/api/streaming/sessions', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], 'not-json');

        self::assertResponseStatusCodeSame(400);
    }

    public function testCreateSessionWithMissingFieldsReturns400(): void
    {
        $this->createUserAndLogin('stream-missing@test.de', 'StreamPass123');
        $this->client->request('POST', '/api/streaming/sessions', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['foo' => 'bar']));

        self::assertResponseStatusCodeSame(400);
    }

    public function testGetSessionStatusReturns404ForUnknownSession(): void
    {
        $this->createUserAndLogin('stream-404@test.de', 'StreamPass123');
        $this->client->request('GET', '/api/streaming/sessions/unknown-session-id');

        self::assertResponseStatusCodeSame(404);
    }

    public function testGetSessionStatusReturnsSessionData(): void
    {
        $user = $this->createUserAndLogin('stream-status@test.de', 'StreamPass123');
        $session = $this->createSessionForUser($user);
        $this->client->request('GET', '/api/streaming/sessions/' . $session->getSessionId());

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame($session->getSessionId(), $data['session_id']);
    }

    public function testGetSessionStatusDeniesForeignSession(): void
    {
        $owner = $this->createUser('stream-owner@test.de', 'StreamPass123');
        $session = $this->createSessionForUser($owner);
        $this->createUserAndLogin('stream-foreign@test.de', 'StreamPass123');

        $this->client->request('GET', '/api/streaming/sessions/' . $session->getSessionId());

        self::assertResponseStatusCodeSame(403);
    }

    public function testListSessionsReturnsOwnSessions(): void
    {
        $user = $this->createUserAndLogin('stream-list@test.de', 'StreamPass123');
        $this->createSessionForUser($user);
        $this->client->request('GET', '/api/streaming/sessions');

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertGreaterThanOrEqual(1, $data['count']);
    }

    public function testListActiveSessionsReturnsOnlyActive(): void
    {
        $user = $this->createUserAndLogin('stream-active@test.de', 'StreamPass123');
        $this->createSessionForUser($user);
        $this->client->request('GET', '/api/streaming/sessions/active');

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('count', $data);
    }

    public function testCancelSessionReturns400ForInactiveSession(): void
    {
        $user = $this->createUserAndLogin('stream-cancel@test.de', 'StreamPass123');
        $session = $this->createSessionForUser($user);
        $this->sessionManager->cancelSession($session->getSessionId(), 'Test-Cancel');
        $this->client->request('POST', '/api/streaming/sessions/' . $session->getSessionId() . '/cancel');

        self::assertResponseStatusCodeSame(400);
    }

    public function testCancelSessionCancelsActiveSession(): void
    {
        $user = $this->createUserAndLogin('stream-cancel2@test.de', 'StreamPass123');
        $session = $this->createSessionForUser($user);
        $this->sessionManager->startSession($session->getSessionId());
        $this->client->request('POST', '/api/streaming/sessions/' . $session->getSessionId() . '/cancel');

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('cancelled', $data['status']);
    }

    public function testCancelSessionReturns404ForUnknownSession(): void
    {
        $this->createUserAndLogin('stream-cancel404@test.de', 'StreamPass123');
        $this->client->request('POST', '/api/streaming/sessions/unknown/cancel');

        self::assertResponseStatusCodeSame(404);
    }

    public function testDeleteSessionRemovesSession(): void
    {
        $user = $this->createUserAndLogin('stream-delete@test.de', 'StreamPass123');
        $session = $this->createSessionForUser($user);
        $this->client->request('DELETE', '/api/streaming/sessions/' . $session->getSessionId());

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('deleted', $data['status']);
        self::assertNull($this->sessionManager->getSession($session->getSessionId()));
    }

    public function testDeleteSessionReturns404ForUnknownSession(): void
    {
        $this->createUserAndLogin('stream-del404@test.de', 'StreamPass123');
        $this->client->request('DELETE', '/api/streaming/sessions/unknown');

        self::assertResponseStatusCodeSame(404);
    }

    public function testStatsRequiresAdmin(): void
    {
        $this->createUserAndLogin('stream-stats-user@test.de', 'StreamPass123');
        $this->client->request('GET', '/api/streaming/stats');

        self::assertResponseStatusCodeSame(403);
    }

    public function testStatsReturnsDataForAdmin(): void
    {
        $this->createUserAndLogin('stream-stats-admin@test.de', 'StreamPass123', ['ROLE_ADMIN']);
        $this->client->request('GET', '/api/streaming/stats');

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('total', $data);
        self::assertArrayHasKey('active', $data);
        self::assertArrayHasKey('by_status', $data);
    }

    public function testCleanupSessionsRequiresAdmin(): void
    {
        $this->createUserAndLogin('stream-cleanup-user@test.de', 'StreamPass123');
        $this->client->request('POST', '/api/streaming/sessions/cleanup', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['days' => 30]));

        self::assertResponseStatusCodeSame(403);
    }

    public function testCleanupSessionsForAdmin(): void
    {
        $this->createUserAndLogin('stream-cleanup-admin@test.de', 'StreamPass123', ['ROLE_ADMIN']);
        $this->client->request('POST', '/api/streaming/sessions/cleanup', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['days' => 30]));

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('cleanup_complete', $data['status']);
        self::assertArrayHasKey('deleted_count', $data);
    }
}
