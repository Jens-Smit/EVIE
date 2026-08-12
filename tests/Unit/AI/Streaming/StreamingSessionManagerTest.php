<?php
// tests/Unit/AI/Streaming/StreamingSessionManagerTest.php

namespace App\Tests\Unit\AI\Streaming;

use App\AI\Streaming\StreamingSessionManager;
use App\Entity\StreamingSession;
use App\Repository\StreamingSessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

class StreamingSessionManagerTest extends TestCase
{
    private StreamingSessionManager $manager;
    private EntityManagerInterface $entityManagerMock;
    private StreamingSessionRepository $sessionRepoMock;
    private LoggerInterface $loggerMock;

    protected function setUp(): void
    {
        $this->entityManagerMock = $this->createMock(EntityManagerInterface::class);
        $this->sessionRepoMock = $this->createMock(StreamingSessionRepository::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);

        $this->manager = new StreamingSessionManager(
            $this->entityManagerMock,
            $this->sessionRepoMock,
            $this->loggerMock
        );
    }

    public function testCreateSession(): void
    {
        $sessionMock = $this->createMock(StreamingSession::class);
        $sessionMock->method('getSessionId')->willReturn('session_123');

        $this->entityManagerMock
            ->method('persist')
            ->with($sessionMock)
            ->willReturn(null);

        $this->entityManagerMock
            ->method('flush')
            ->willReturn(null);

        $this->entityManagerMock
            ->method('getRepository')
            ->willReturn($this->createMock(\Doctrine\ORM\EntityRepository::class));

        $result = $this->manager->createSession(
            'test_tool',
            ['arg1' => 'value1'],
            'user_123'
        );

        $this->assertNotNull($result);
        $this->assertEquals('test_tool', $result->getToolName());
    }

    public function testStartSession(): void
    {
        $sessionMock = $this->createMock(StreamingSession::class);
        $sessionMock->method('getSessionId')->willReturn('session_123');
        $sessionMock->method('getToolName')->willReturn('test_tool');

        $this->sessionRepoMock
            ->method('findOneBySessionId')
            ->with('session_123')
            ->willReturn($sessionMock);

        $this->entityManagerMock
            ->method('flush')
            ->willReturn(null);

        $result = $this->manager->startSession('session_123');

        $this->assertSame($sessionMock, $result);
    }

    public function testStartSessionNotFound(): void
    {
        $this->sessionRepoMock
            ->method('findOneBySessionId')
            ->with('nonexistent')
            ->willReturn(null);

        $result = $this->manager->startSession('nonexistent');

        $this->assertNull($result);
    }

    public function testUpdateProgress(): void
    {
        $sessionMock = $this->createMock(StreamingSession::class);
        $sessionMock->method('getSessionId')->willReturn('session_123');

        $this->sessionRepoMock
            ->method('findOneBySessionId')
            ->with('session_123')
            ->willReturn($sessionMock);

        $this->entityManagerMock
            ->method('flush')
            ->willReturn(null);

        $result = $this->manager->updateProgress(
            'session_123',
            50.0,
            'Processing...',
            ['partial' => 'result']
        );

        $this->assertSame($sessionMock, $result);
    }

    public function testUpdateProgressNotFound(): void
    {
        $this->sessionRepoMock
            ->method('findOneBySessionId')
            ->with('nonexistent')
            ->willReturn(null);

        $result = $this->manager->updateProgress('nonexistent', 50.0, 'Processing...');

        $this->assertNull($result);
    }

    public function testCompleteSession(): void
    {
        $sessionMock = $this->createMock(StreamingSession::class);
        $sessionMock->method('getSessionId')->willReturn('session_123');
        $sessionMock->method('getToolName')->willReturn('test_tool');
        $sessionMock->method('getDuration')->willReturn(300);

        $this->sessionRepoMock
            ->method('findOneBySessionId')
            ->with('session_123')
            ->willReturn($sessionMock);

        $this->entityManagerMock
            ->method('flush')
            ->willReturn(null);

        $result = $this->manager->completeSession(
            'session_123',
            ['final' => 'result'],
            'corr_123'
        );

        $this->assertSame($sessionMock, $result);
    }

    public function testCompleteSessionNotFound(): void
    {
        $this->sessionRepoMock
            ->method('findOneBySessionId')
            ->with('nonexistent')
            ->willReturn(null);

        $result = $this->manager->completeSession('nonexistent', []);

        $this->assertNull($result);
    }

    public function testFailSession(): void
    {
        $sessionMock = $this->createMock(StreamingSession::class);
        $sessionMock->method('getSessionId')->willReturn('session_123');
        $sessionMock->method('getToolName')->willReturn('test_tool');
        $sessionMock->method('getDuration')->willReturn(300);

        $this->sessionRepoMock
            ->method('findOneBySessionId')
            ->with('session_123')
            ->willReturn($sessionMock);

        $this->entityManagerMock
            ->method('flush')
            ->willReturn(null);

        $result = $this->manager->failSession(
            'session_123',
            'Test error',
            ['code' => 500],
            'corr_123'
        );

        $this->assertSame($sessionMock, $result);
    }

    public function testFailSessionNotFound(): void
    {
        $this->sessionRepoMock
            ->method('findOneBySessionId')
            ->with('nonexistent')
            ->willReturn(null);

        $result = $this->manager->failSession('nonexistent', 'Error');

        $this->assertNull($result);
    }

    public function testCancelSession(): void
    {
        $sessionMock = $this->createMock(StreamingSession::class);
        $sessionMock->method('getSessionId')->willReturn('session_123');
        $sessionMock->method('getToolName')->willReturn('test_tool');
        $sessionMock->method('getDuration')->willReturn(300);

        $this->sessionRepoMock
            ->method('findOneBySessionId')
            ->with('session_123')
            ->willReturn($sessionMock);

        $this->entityManagerMock
            ->method('flush')
            ->willReturn(null);

        $result = $this->manager->cancelSession(
            'session_123',
            'User cancelled',
            'corr_123'
        );

        $this->assertSame($sessionMock, $result);
    }

    public function testGetSession(): void
    {
        $sessionMock = $this->createMock(StreamingSession::class);

        $this->sessionRepoMock
            ->method('findOneBySessionId')
            ->with('session_123')
            ->willReturn($sessionMock);

        $result = $this->manager->getSession('session_123');

        $this->assertSame($sessionMock, $result);
    }

    public function testGetSessionNotFound(): void
    {
        $this->sessionRepoMock
            ->method('findOneBySessionId')
            ->with('nonexistent')
            ->willReturn(null);

        $result = $this->manager->getSession('nonexistent');

        $this->assertNull($result);
    }

    public function testHasSession(): void
    {
        $this->sessionRepoMock
            ->method('existsBySessionId')
            ->with('session_123')
            ->willReturn(true);

        $result = $this->manager->hasSession('session_123');

        $this->assertTrue($result);
    }

    public function testIsSessionActive(): void
    {
        $sessionMock = $this->createMock(StreamingSession::class);
        $sessionMock->method('isActive')->willReturn(true);

        $this->sessionRepoMock
            ->method('findOneBySessionId')
            ->with('session_123')
            ->willReturn($sessionMock);

        $result = $this->manager->isSessionActive('session_123');

        $this->assertTrue($result);
    }

    public function testIsSessionFinished(): void
    {
        $sessionMock = $this->createMock(StreamingSession::class);
        $sessionMock->method('isFinished')->willReturn(true);

        $this->sessionRepoMock
            ->method('findOneBySessionId')
            ->with('session_123')
            ->willReturn($sessionMock);

        $result = $this->manager->isSessionFinished('session_123');

        $this->assertTrue($result);
    }

    public function testGenerateSessionId(): void
    {
        $sessionId = $this->manager->generateSessionId();

        $this->assertNotEmpty($sessionId);
        $this->assertEquals(36, strlen($sessionId)); // UUID v4 format
    }

    public function testGetActiveSessions(): void
    {
        $sessionMock = $this->createMock(StreamingSession::class);

        $this->sessionRepoMock
            ->method('findAllActive')
            ->willReturn([$sessionMock]);

        $result = $this->manager->getActiveSessions();

        $this->assertCount(1, $result);
        $this->assertSame($sessionMock, $result[0]);
    }

    public function testGetRunningSessions(): void
    {
        $sessionMock = $this->createMock(StreamingSession::class);

        $this->sessionRepoMock
            ->method('findAllRunning')
            ->willReturn([$sessionMock]);

        $result = $this->manager->getRunningSessions();

        $this->assertCount(1, $result);
    }

    public function testGetFinishedSessions(): void
    {
        $sessionMock = $this->createMock(StreamingSession::class);

        $this->sessionRepoMock
            ->method('findAllFinished')
            ->willReturn([$sessionMock]);

        $result = $this->manager->getFinishedSessions();

        $this->assertCount(1, $result);
    }

    public function testGetSessionsByUser(): void
    {
        $sessionMock = $this->createMock(StreamingSession::class);

        $this->sessionRepoMock
            ->method('findByUser')
            ->with('user_123')
            ->willReturn([$sessionMock]);

        $result = $this->manager->getSessionsByUser('user_123');

        $this->assertCount(1, $result);
    }

    public function testGetActiveSessionsByUser(): void
    {
        $sessionMock = $this->createMock(StreamingSession::class);

        $this->sessionRepoMock
            ->method('findActiveByUser')
            ->with('user_123')
            ->willReturn([$sessionMock]);

        $result = $this->manager->getActiveSessionsByUser('user_123');

        $this->assertCount(1, $result);
    }

    public function testCleanupFinishedSessions(): void
    {
        $this->sessionRepoMock
            ->method('deleteFinishedOlderThan')
            ->with(30)
            ->willReturn(5);

        $result = $this->manager->cleanupFinishedSessions(30);

        $this->assertEquals(5, $result);
    }

    public function testCountActiveSessions(): void
    {
        $this->sessionRepoMock
            ->method('countActive')
            ->willReturn(3);

        $result = $this->manager->countActiveSessions();

        $this->assertEquals(3, $result);
    }

    public function testCountSessionsByStatus(): void
    {
        $this->sessionRepoMock
            ->method('countByStatus')
            ->willReturn([
                'pending' => 1,
                'running' => 2,
                'completed' => 3,
            ]);

        $result = $this->manager->countSessionsByStatus();

        $this->assertCount(3, $result);
        $this->assertEquals(1, $result['pending']);
        $this->assertEquals(2, $result['running']);
        $this->assertEquals(3, $result['completed']);
    }
}
