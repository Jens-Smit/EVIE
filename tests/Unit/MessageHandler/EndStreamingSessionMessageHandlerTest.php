<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\AI\Streaming\StreamingPublisher;
use App\AI\Streaming\StreamingSessionManager;
use App\Message\EndStreamingSessionMessage;
use App\MessageHandler\EndStreamingSessionMessageHandler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit-Tests fuer EndStreamingSessionMessageHandler (Session-Ende + Mercure-Publishing).
 */
final class EndStreamingSessionMessageHandlerTest extends TestCase
{
    private StreamingSessionManager&MockObject $sessionManager;
    private StreamingPublisher&MockObject $publisher;
    private EndStreamingSessionMessageHandler $handler;

    protected function setUp(): void
    {
        $this->sessionManager = $this->createMock(StreamingSessionManager::class);
        $this->publisher = $this->createMock(StreamingPublisher::class);
        $this->handler = new EndStreamingSessionMessageHandler(
            $this->sessionManager,
            $this->publisher,
            new NullLogger()
        );
    }

    public function testInvokePublishesSessionEnd(): void
    {
        $message = EndStreamingSessionMessage::createSuccess(
            'sess1',
            'ToolA',
            ['result' => 'ok']
        );

        $this->publisher
            ->expects(self::once())
            ->method('publishSessionEnd')
            ->with('sess1', 'ToolA', true, 'completed', ['result' => 'ok']);

        ($this->handler)($message);
    }

    public function testInvokePublishesFailedSessionEnd(): void
    {
        $message = EndStreamingSessionMessage::createFailure(
            'sess1',
            'ToolA',
            'Tool crashed',
            ['attempt' => 1]
        );

        $this->publisher
            ->expects(self::once())
            ->method('publishSessionEnd')
            ->with('sess1', 'ToolA', false, 'failed', ['attempt' => 1, 'error' => 'Tool crashed']);

        ($this->handler)($message);
    }

    public function testInvokePublishesCancelledSessionEnd(): void
    {
        $message = EndStreamingSessionMessage::createCancelled('sess1', 'ToolA', 'User cancelled');

        $this->publisher
            ->expects(self::once())
            ->method('publishSessionEnd')
            ->with('sess1', 'ToolA', false, 'cancelled', ['reason' => 'User cancelled']);

        ($this->handler)($message);
    }

    public function testInvokeLogsErrorButDoesNotThrowOnPublisherException(): void
    {
        $message = EndStreamingSessionMessage::createSuccess('sess1', 'ToolA');
        $this->publisher
            ->method('publishSessionEnd')
            ->willThrowException(new \RuntimeException('Mercure down'));

        ($this->handler)($message);

        $this->addToAssertionCount(1);
    }
}
