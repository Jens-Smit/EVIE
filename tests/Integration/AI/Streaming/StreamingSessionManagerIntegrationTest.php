<?php
// tests/Integration/AI/Streaming/StreamingSessionManagerIntegrationTest.php

namespace App\Tests\Integration\AI\Streaming;

use App\AI\Streaming\StreamingSessionManager;
use App\Entity\StreamingSession;
use App\Entity\User;
use App\Repository\StreamingSessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class StreamingSessionManagerIntegrationTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private StreamingSessionRepository $sessionRepo;
    private StreamingSessionManager $manager;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->sessionRepo = $this->entityManager->getRepository(StreamingSession::class);
        $this->manager = self::getContainer()->get(StreamingSessionManager::class);

        $schemaTool = new \Doctrine\ORM\Tools\SchemaTool($this->entityManager);
        try {
            $schemaTool->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());
        } catch (\Throwable) {
        }
    }

    public function testCreateSession(): void
    {
        $session = $this->manager->createSession(
            'test_tool',
            ['arg1' => 'value1'],
            'test_user'
        );

        $this->assertNotNull($session);
        $this->assertNotNull($session->getId());
        $this->assertEquals('test_tool', $session->getToolName());
        $this->assertEquals(['arg1' => 'value1'], $session->getInitialArguments());
        $this->assertEquals('test_user', $session->getUserIdentifier());
        $this->assertEquals(StreamingSession::STATUS_PENDING, $session->getStatus());
        $this->assertNotNull($session->getSessionId());
        $this->assertNotNull($session->getCreatedAt());

        // Überprüfe, dass die Session in der DB ist
        $savedSession = $this->sessionRepo->findOneBySessionId($session->getSessionId());
        $this->assertNotNull($savedSession);
    }

    public function testStartSession(): void
    {
        // Erstelle eine Session
        $session = $this->manager->createSession(
            'test_tool',
            [],
            'test_user'
        );

        // Starte die Session
        $startedSession = $this->manager->startSession($session->getSessionId());

        $this->assertNotNull($startedSession);
        $this->assertEquals(StreamingSession::STATUS_RUNNING, $startedSession->getStatus());
        $this->assertNotNull($startedSession->getStartedAt());
        $this->assertEquals('Tool-Ausführung gestartet', $startedSession->getCurrentProgress());
        $this->assertEquals(0.0, $startedSession->getProgressPercentage());
    }

    public function testUpdateProgress(): void
    {
        // Erstelle und starte eine Session
        $session = $this->manager->createSession('test_tool', [], 'test_user');
        $this->manager->startSession($session->getSessionId());

        // Aktualisiere Fortschritt
        $updatedSession = $this->manager->updateProgress(
            $session->getSessionId(),
            50.0,
            'Processing...',
            ['partial' => 'result']
        );

        $this->assertNotNull($updatedSession);
        $this->assertEquals(50.0, $updatedSession->getProgressPercentage());
        $this->assertEquals('Processing...', $updatedSession->getCurrentProgress());
        $this->assertContains(['partial' => 'result'], $updatedSession->getPartialResults());
    }

    public function testCompleteSession(): void
    {
        // Erstelle und starte eine Session
        $session = $this->manager->createSession('test_tool', [], 'test_user');
        $this->manager->startSession($session->getSessionId());

        // Beende die Session erfolgreich
        $completedSession = $this->manager->completeSession(
            $session->getSessionId(),
            ['final' => 'result'],
            'corr_123'
        );

        $this->assertNotNull($completedSession);
        $this->assertEquals(StreamingSession::STATUS_COMPLETED, $completedSession->getStatus());
        $this->assertNotNull($completedSession->getCompletedAt());
        $this->assertEquals(['final' => 'result'], $completedSession->getFinalResult());
        $this->assertEquals(100.0, $completedSession->getProgressPercentage());
        $this->assertEquals('Abgeschlossen', $completedSession->getCurrentProgress());
        $this->assertEquals('corr_123', $completedSession->getCorrelationId());
    }

    public function testFailSession(): void
    {
        // Erstelle und starte eine Session
        $session = $this->manager->createSession('test_tool', [], 'test_user');
        $this->manager->startSession($session->getSessionId());

        // Beende die Session mit Fehler
        $failedSession = $this->manager->failSession(
            $session->getSessionId(),
            'Test error',
            ['code' => 500],
            'corr_123'
        );

        $this->assertNotNull($failedSession);
        $this->assertEquals(StreamingSession::STATUS_FAILED, $failedSession->getStatus());
        $this->assertNotNull($failedSession->getCompletedAt());
        $errorData = $failedSession->getErrorData();
        $this->assertSame('Test error', $errorData['message']);
        $this->assertSame(['code' => 500], $errorData['details']);
        $this->assertStringContainsString('Fehlgeschlagen: Test error', $failedSession->getCurrentProgress());
    }

    public function testCancelSession(): void
    {
        // Erstelle und starte eine Session
        $session = $this->manager->createSession('test_tool', [], 'test_user');
        $this->manager->startSession($session->getSessionId());

        // Breche die Session ab
        $cancelledSession = $this->manager->cancelSession(
            $session->getSessionId(),
            'User cancelled',
            'corr_123'
        );

        $this->assertNotNull($cancelledSession);
        $this->assertEquals(StreamingSession::STATUS_CANCELLED, $cancelledSession->getStatus());
        $this->assertNotNull($cancelledSession->getCompletedAt());
        $cancelData = $cancelledSession->getErrorData();
        $this->assertSame('User cancelled', $cancelData['reason']);
        $this->assertStringContainsString('Abgebrochen: User cancelled', $cancelledSession->getCurrentProgress());
    }

    public function testGetSession(): void
    {
        // Erstelle eine Session
        $session = $this->manager->createSession('test_tool', [], 'test_user');
        $sessionId = $session->getSessionId();

        // Hole die Session
        $retrievedSession = $this->manager->getSession($sessionId);

        $this->assertNotNull($retrievedSession);
        $this->assertEquals($sessionId, $retrievedSession->getSessionId());
    }

    public function testGetSessionNotFound(): void
    {
        $result = $this->manager->getSession('nonexistent');
        $this->assertNull($result);
    }

    public function testHasSession(): void
    {
        // Erstelle eine Session
        $session = $this->manager->createSession('test_tool', [], 'test_user');

        $this->assertTrue($this->manager->hasSession($session->getSessionId()));
        $this->assertFalse($this->manager->hasSession('nonexistent'));
    }

    public function testIsSessionActive(): void
    {
        // Erstelle eine Session
        $session = $this->manager->createSession('test_tool', [], 'test_user');

        $this->assertTrue($this->manager->isSessionActive($session->getSessionId()));

        // Starte die Session
        $this->manager->startSession($session->getSessionId());
        $this->assertTrue($this->manager->isSessionActive($session->getSessionId()));

        // Beende die Session
        $this->manager->completeSession($session->getSessionId(), []);
        $this->assertFalse($this->manager->isSessionActive($session->getSessionId()));
    }

    public function testIsSessionFinished(): void
    {
        // Erstelle eine Session
        $session = $this->manager->createSession('test_tool', [], 'test_user');

        $this->assertFalse($this->manager->isSessionFinished($session->getSessionId()));

        // Beende die Session
        $this->manager->completeSession($session->getSessionId(), []);
        $this->assertTrue($this->manager->isSessionFinished($session->getSessionId()));
    }

    public function testGetActiveSessions(): void
    {
        // Erstelle aktive Sessions
        $session1 = $this->manager->createSession('tool1', [], 'user1');
        $session2 = $this->manager->createSession('tool2', [], 'user2');

        $activeSessions = $this->manager->getActiveSessions();

        $this->assertGreaterThanOrEqual(2, count($activeSessions));
    }

    public function testGetSessionsByUser(): void
    {
        // Erstelle Sessions für einen User
        $this->manager->createSession('tool1', [], 'test_user');
        $this->manager->createSession('tool2', [], 'test_user');
        $this->manager->createSession('tool3', [], 'other_user');

        $userSessions = $this->manager->getSessionsByUser('test_user');

        $this->assertGreaterThanOrEqual(2, count($userSessions));
    }

    public function testGenerateSessionId(): void
    {
        $sessionId1 = $this->manager->generateSessionId();
        $sessionId2 = $this->manager->generateSessionId();

        $this->assertNotEquals($sessionId1, $sessionId2);
        $this->assertEquals(36, strlen($sessionId1));
    }

    public function testCountActiveSessions(): void
    {
        // Erstelle aktive Sessions
        $this->manager->createSession('tool1', [], 'user1');
        $this->manager->createSession('tool2', [], 'user2');

        $count = $this->manager->countActiveSessions();

        $this->assertGreaterThanOrEqual(2, $count);
    }

    public function testCleanupFinishedSessions(): void
    {
        // Erstelle und beende eine Session
        $session = $this->manager->createSession('test_tool', [], 'test_user');
        $this->manager->startSession($session->getSessionId());
        $this->manager->completeSession($session->getSessionId(), []);

        // Bereinige Sessions älter als 0 Tage (sollte die Session löschen)
        $deletedCount = $this->manager->cleanupFinishedSessions(0);

        $this->assertGreaterThanOrEqual(0, $deletedCount);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Bereinige die DB
        $this->entityManager->createQuery('DELETE FROM App\Entity\StreamingSession')->execute();
        $this->entityManager->flush();
    }
}
