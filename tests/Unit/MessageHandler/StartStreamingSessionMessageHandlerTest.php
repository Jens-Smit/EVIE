<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\AI\Streaming\StreamingPublisher;
use App\AI\Streaming\StreamingSessionManager;
use App\Entity\StreamingSession;
use App\Message\StartStreamingSessionMessage;
use App\MessageHandler\StartStreamingSessionMessageHandler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit-Tests fuer StartStreamingSessionMessageHandler (Session-Start + Mercure-Publishing).
 */
final class StartStreamingSessionMessageHandlerTest extends TestCase
{
    private StreamingSessionManager&MockObject $sessionManager;
    private StreamingPublisher&MockObject $publisher;
    private StartStreamingSessionMessageHandler $handler;

    protected function setUp(): void
    {
        $this->sessionManager = $this->createMock(StreamingSessionManager::class);
        $this->publisher = $this->createMock(StreamingPublisher::class);
        $this->handler = new StartStreamingSessionMessageHandler(
            $this->sessionManager,
            $this->publisher,
            new NullLogger()
        );
    }

    public function testInvokePublishesSessionStart(): void
    {
        $message = new StartStreamingSessionMessage(
            'sess1',
            'ToolA',
            ['query' => 'test'],
            'user@example.com'
        );

        $this->sessionManager->method('getSession')->willReturn(null);
        $this->publisher
            ->expects(self::once())
            ->method('publishSessionStart')
            ->with('sess1', 'ToolA', ['query' => 'test'], 'user@example.com');

        ($this->handler)($message);
    }

    public function testInvokeStartsInactiveSession(): void
    {
        $session = $this->createMock(StreamingSession::class);
        $session->method('isActive')->willReturn(false);
        $this->sessionManager->method('getSession')->willReturn($session);
        $this->sessionManager
            ->expects(self::once())
            ->method('startSession')
            ->with('sess1');

        $message = new StartStreamingSessionMessage('sess1', 'ToolA', [], 'user@example.com');

        $this->publisher->expects(self::once())->method('publishSessionStart');

        ($this->handler)($message);
    }

    public function testInvokeDoesNotRestartActiveSession(): void
    {
        $session = $this->createMock(StreamingSession::class);
        $session->method('isActive')->willReturn(true);
        $this->sessionManager->method('getSession')->willReturn($session);
        $this->sessionManager->expects(self::never())->method('startSession');

        $message = new StartStreamingSessionMessage('sess1', 'ToolA', [], 'user@example.com');

        $this->publisher->expects(self::once())->method('publishSessionStart');

        ($this->handler)($message);
    }

    public function testInvokePublishesErrorOnException(): void
    {
        $this->sessionManager->method('getSession')->willThrowException(new \RuntimeException('Session error'));

        $message = new StartStreamingSessionMessage('sess1', 'ToolA', [], 'user@example.com');

        $this->publisher->expects(self::never())->method('publishSessionStart');
        $this->publisher
            ->expects(self::once())
            ->method('publishError')
            ->with('sess1', 'Fehler beim Starten der Session: Session error');

        ($this->handler)($message);
    }
}
