<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\AI\Streaming\StreamingPublisher;
use App\AI\Streaming\StreamingSessionManager;
use App\Entity\StreamingSession;
use App\Message\StreamToolResponseMessage;
use App\MessageHandler\StreamToolResponseMessageHandler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit-Tests fuer StreamToolResponseMessageHandler (Streaming-Chunk-Verarbeitung).
 */
final class StreamToolResponseMessageHandlerTest extends TestCase
{
    private StreamingSessionManager&MockObject $sessionManager;
    private StreamingPublisher&MockObject $publisher;
    private StreamToolResponseMessageHandler $handler;

    protected function setUp(): void
    {
        $this->sessionManager = $this->createMock(StreamingSessionManager::class);
        $this->publisher = $this->createMock(StreamingPublisher::class);
        $this->handler = new StreamToolResponseMessageHandler(
            $this->sessionManager,
            new NullLogger(),
            $this->publisher
        );
    }

    public function testInvokeWithProgressChunkUpdatesSession(): void
    {
        $session = $this->createMock(StreamingSession::class);
        $this->sessionManager->method('getSession')->willReturn($session);
        $this->sessionManager
            ->expects(self::once())
            ->method('updateProgress')
            ->with('sess1', 50.0, 'Processing', ['percentage' => 50.0, 'message' => 'Processing']);

        $chunk = ['percentage' => 50.0, 'message' => 'Processing'];
        $message = new StreamToolResponseMessage('sess1', 'ToolA', $chunk, 'progress');
        ($this->handler)($message);
    }

    public function testInvokeWithProgressChunkWithoutPercentageDoesNotUpdate(): void
    {
        $session = $this->createMock(StreamingSession::class);
        $this->sessionManager->method('getSession')->willReturn($session);
        $this->sessionManager->expects(self::never())->method('updateProgress');

        $message = StreamToolResponseMessage::createProgress('sess1', 'ToolA', 50.0, 'Processing');
        ($this->handler)($message);
    }

    public function testInvokeWithPartialResultAddsToSession(): void
    {
        $session = $this->createMock(StreamingSession::class);
        $this->sessionManager->method('getSession')->willReturn($session);
        $this->sessionManager
            ->expects(self::once())
            ->method('addPartialResult')
            ->with('sess1', 'partial-data');

        $message = new StreamToolResponseMessage('sess1', 'ToolA', 'partial-data', 'partial_result');
        ($this->handler)($message);
    }

    public function testInvokeWithNoSessionDoesNothing(): void
    {
        $this->sessionManager->method('getSession')->willReturn(null);
        $this->sessionManager->expects(self::never())->method('updateProgress');
        $this->sessionManager->expects(self::never())->method('addPartialResult');

        $message = new StreamToolResponseMessage('sess1', 'ToolA', ['percentage' => 10.0], 'progress');
        ($this->handler)($message);
    }

    public function testInvokeWithFinalChunkLogsInfo(): void
    {
        $session = $this->createMock(StreamingSession::class);
        $this->sessionManager->method('getSession')->willReturn($session);

        $message = StreamToolResponseMessage::createFinalResult('sess1', 'ToolA', 'final result');
        ($this->handler)($message);

        self::assertTrue($message->isFinal());
    }

    public function testInvokeHandlesExceptionAndFailsSession(): void
    {
        $session = $this->createMock(StreamingSession::class);
        $this->sessionManager->method('getSession')->willReturn($session);
        $this->sessionManager
            ->method('updateProgress')
            ->willThrowException(new \RuntimeException('Session error'));
        $this->sessionManager
            ->expects(self::once())
            ->method('failSession');

        $chunk = ['percentage' => 50.0, 'message' => 'Processing'];
        $message = new StreamToolResponseMessage('sess1', 'ToolA', $chunk, 'progress');
        ($this->handler)($message);
    }

    public function testInvokeHandlesExceptionWithoutSession(): void
    {
        $this->sessionManager->method('getSession')->willReturn(null);
        $this->sessionManager->expects(self::never())->method('failSession');

        $message = new StreamToolResponseMessage('sess1', 'ToolA', 'data', 'progress');
        ($this->handler)($message);
    }
}
