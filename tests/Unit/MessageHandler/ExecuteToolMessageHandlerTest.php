<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\AI\Skills\Tool\DynamicToolExecutor;
use App\AI\Skills\Tool\DynamicToolFactory;
use App\AI\Skills\Tool\DynamicTool;
use App\Entity\StreamingSession;
use App\AI\Streaming\StreamingSessionManager;
use App\Message\EndStreamingSessionMessage;
use App\Message\ExecuteToolMessage;
use App\Message\StartStreamingSessionMessage;
use App\Message\StreamToolResponseMessage;
use App\MessageHandler\ExecuteToolMessageHandler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Unit-Tests für ExecuteToolMessageHandler (asynchrone Tool-Ausführung mit Streaming).
 */
final class ExecuteToolMessageHandlerTest extends TestCase
{
    private DynamicToolExecutor&MockObject $toolExecutor;
    private DynamicToolFactory&MockObject $toolFactory;
    private StreamingSessionManager&MockObject $sessionManager;
    private MessageBusInterface&MockObject $messageBus;
    private ExecuteToolMessageHandler $handler;

    protected function setUp(): void
    {
        $this->toolExecutor = $this->createMock(DynamicToolExecutor::class);
        $this->toolFactory = $this->createMock(DynamicToolFactory::class);
        $this->sessionManager = $this->createMock(StreamingSessionManager::class);
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->handler = new ExecuteToolMessageHandler(
            $this->toolExecutor,
            $this->toolFactory,
            $this->sessionManager,
            $this->messageBus,
            new NullLogger()
        );
    }

    public function testInvokeCreatesSessionWhenNotExistingAndExecutes(): void
    {
        $session = $this->createMock(StreamingSession::class);
        $session->method('getSessionId')->willReturn('sess-1');

        $this->sessionManager->method('getSession')->willReturn(null);
        $this->sessionManager
            ->expects(self::once())
            ->method('createSession')
            ->with('WeatherTool', ['city' => 'Berlin'], 'tenant1')
            ->willReturn($session);

        $this->sessionManager->expects(self::once())->method('startSession');
        $this->sessionManager->expects(self::once())->method('updateProgress');
        $this->sessionManager->expects(self::once())->method('completeSession');

        $dynamicTool = $this->createMock(DynamicTool::class);
        $this->toolFactory->method('getTool')->with('WeatherTool')->willReturn($dynamicTool);

        $this->toolExecutor
            ->expects(self::once())
            ->method('execute')
            ->willReturn(new \App\AI\Skills\Tool\ToolExecutionResult('WeatherTool', true, null, 'sunny'));

        $this->messageBus
            ->expects(self::exactly(3))
            ->method('dispatch')
            ->willReturnCallback(fn ($msg) => new Envelope($msg));

        $message = new ExecuteToolMessage('WeatherTool', ['city' => 'Berlin'], 'tenant1', 'sess-0');

        ($this->handler)($message);
    }

    public function testInvokeUsesExistingSessionWhenFound(): void
    {
        $session = $this->createMock(StreamingSession::class);
        $session->method('getSessionId')->willReturn('sess-existing');

        $this->sessionManager->method('getSession')->willReturn($session);
        $this->sessionManager->expects(self::never())->method('createSession');
        $this->sessionManager->expects(self::once())->method('startSession');
        $this->sessionManager->expects(self::once())->method('completeSession');

        $dynamicTool = $this->createMock(DynamicTool::class);
        $this->toolFactory->method('getTool')->willReturn($dynamicTool);

        $this->toolExecutor->method('execute')->willReturn(new \App\AI\Skills\Tool\ToolExecutionResult('ToolA', true, null, 'result'));

        $this->messageBus->expects(self::exactly(3))->method('dispatch')
            ->willReturnCallback(fn ($msg) => new Envelope($msg));

        $message = new ExecuteToolMessage('ToolA', [], 'tenant1', 'sess-existing');

        ($this->handler)($message);
    }

    public function testInvokeHandlesExceptionAndFailsSession(): void
    {
        $session = $this->createMock(StreamingSession::class);
        $session->method('getSessionId')->willReturn('sess-1');

        $this->sessionManager->method('getSession')->willReturn($session);
        $this->sessionManager->expects(self::once())->method('startSession');
        $this->sessionManager->expects(self::once())->method('failSession');
        $this->sessionManager->expects(self::never())->method('completeSession');

        $dynamicTool = $this->createMock(DynamicTool::class);
        $this->toolFactory->method('getTool')->willReturn($dynamicTool);

        $this->toolExecutor
            ->method('execute')
            ->willThrowException(new \RuntimeException('Tool failed'));

        $this->messageBus
            ->expects(self::exactly(3))
            ->method('dispatch')
            ->willReturnCallback(fn ($msg) => new Envelope($msg));

        $message = new ExecuteToolMessage('ToolA', [], 'tenant1', 'sess-1');

        ($this->handler)($message);
    }

    public function testInvokeHandlesExceptionWithoutSession(): void
    {
        $this->sessionManager->method('getSession')->willReturn(null);
        $this->sessionManager->method('createSession')->willThrowException(new \RuntimeException('Cannot create'));

        $this->toolFactory->expects(self::never())->method('getTool');
        $this->toolExecutor->expects(self::never())->method('execute');

        $this->messageBus
            ->expects(self::exactly(2))
            ->method('dispatch')
            ->willReturnCallback(fn ($msg) => new Envelope($msg));

        $message = new ExecuteToolMessage('ToolA', [], 'tenant1', 'sess-missing');

        ($this->handler)($message);
    }
}
