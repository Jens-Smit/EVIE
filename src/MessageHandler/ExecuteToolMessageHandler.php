<?php
// src/MessageHandler/ExecuteToolMessageHandler.php

namespace App\MessageHandler;

use App\AI\Skills\Tool\DynamicToolExecutor;
use App\AI\Skills\Tool\DynamicToolFactory;
use App\AI\Streaming\StreamingSessionManager;
use App\Message\ExecuteToolMessage;
use App\Message\StreamToolResponseMessage;
use App\Message\StartStreamingSessionMessage;
use App\Message\EndStreamingSessionMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Handler für ExecuteToolMessage.
 * Führt Tools asynchron aus und sendet Streaming-Updates.
 */
#[AsMessageHandler]
class ExecuteToolMessageHandler
{
    private DynamicToolExecutor $toolExecutor;
    private DynamicToolFactory $toolFactory;
    private StreamingSessionManager $sessionManager;
    private MessageBusInterface $messageBus;
    private LoggerInterface $logger;

    public function __construct(
        DynamicToolExecutor $toolExecutor,
        DynamicToolFactory $toolFactory,
        StreamingSessionManager $sessionManager,
        MessageBusInterface $messageBus,
        LoggerInterface $logger
    ) {
        $this->toolExecutor = $toolExecutor;
        $this->toolFactory = $toolFactory;
        $this->sessionManager = $sessionManager;
        $this->messageBus = $messageBus;
        $this->logger = $logger;
    }

    /**
     * Verarbeitet eine ExecuteToolMessage.
     */
    public function __invoke(ExecuteToolMessage $message): void
    {
        $sessionId = $message->getSessionId();
        $toolName = $message->getToolName();
        $arguments = $message->getArguments();
        $userIdentifier = $message->getUserIdentifier();
        $correlationId = $message->getCorrelationId();

        $this->logger->info('Verarbeite ExecuteToolMessage', [
            'session_id' => $sessionId,
            'tool_name' => $toolName,
            'correlation_id' => $correlationId,
        ]);

        try {
            // 1. Session erstellen oder aktualisieren
            $session = $this->sessionManager->getSession($sessionId);
            if ($session === null) {
                $session = $this->sessionManager->createSession(
                    $toolName,
                    $arguments,
                    $userIdentifier
                );
                $sessionId = $session->getSessionId();
            }

            // 2. Session starten
            $this->sessionManager->startSession($sessionId);

            // 3. Start-Message senden
            $this->messageBus->dispatch(new StartStreamingSessionMessage(
                $sessionId,
                $toolName,
                $arguments,
                $userIdentifier,
                $correlationId
            ));

            // 4. Tool auflösen und ausführen
            $this->logger->debug('Führe Tool aus', [
                'session_id' => $sessionId,
                'tool_name' => $toolName,
            ]);

            $tool = $this->toolFactory->getTool($toolName);
            if ($tool === null) {
                throw new \RuntimeException(sprintf('Tool "%s" nicht gefunden', $toolName));
            }

            $result = $this->toolExecutor->execute($tool, $arguments);

            // 5. Fortschritt aktualisieren (100%)
            $this->sessionManager->updateProgress(
                $sessionId,
                100.0,
                'Tool-Ausführung abgeschlossen',
                $result
            );

            // 6. Finales Ergebnis senden
            $finalResult = $result->isSuccess() ? ['data' => $result->getResult()] : ['error' => $result->getErrorMessage()];

            $this->messageBus->dispatch(StreamToolResponseMessage::createFinalResult(
                $sessionId,
                $toolName,
                $finalResult,
                $correlationId
            ));

            // 7. Session als abgeschlossen markieren
            $this->sessionManager->completeSession($sessionId, $finalResult, $correlationId);

            // 8. End-Message senden
            $this->messageBus->dispatch(new EndStreamingSessionMessage(
                $sessionId,
                $toolName,
                true,
                'completed',
                ['result_type' => gettype($result)],
                $correlationId
            ));

            $this->logger->info('Tool-Ausführung erfolgreich abgeschlossen', [
                'session_id' => $sessionId,
                'tool_name' => $toolName,
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Fehler bei der Tool-Ausführung', [
                'session_id' => $sessionId,
                'tool_name' => $toolName,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // 1. Fehler-Message senden
            $this->messageBus->dispatch(StreamToolResponseMessage::createError(
                $sessionId,
                $toolName,
                $e->getMessage(),
                ['exception' => get_class($e)],
                $correlationId
            ));

            // 2. Session als fehlgeschlagen markieren
            if ($session !== null) {
                $this->sessionManager->failSession(
                    $sessionId,
                    $e->getMessage(),
                    ['exception' => get_class($e)],
                    $correlationId
                );
            }

            // 3. End-Message senden
            $this->messageBus->dispatch(new EndStreamingSessionMessage(
                $sessionId,
                $toolName,
                false,
                'failed',
                ['error' => $e->getMessage()],
                $correlationId
            ));
        }
    }
}
