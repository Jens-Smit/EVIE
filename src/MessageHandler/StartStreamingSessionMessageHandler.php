<?php
// src/MessageHandler/StartStreamingSessionMessageHandler.php

namespace App\MessageHandler;

use App\AI\Streaming\StreamingPublisher;
use App\AI\Streaming\StreamingSessionManager;
use App\Message\StartStreamingSessionMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handler für StartStreamingSessionMessage.
 * Initialisiert eine neue Streaming-Session und benachrichtigt Clients.
 */
#[AsMessageHandler]
class StartStreamingSessionMessageHandler
{
    private StreamingSessionManager $sessionManager;
    private StreamingPublisher $streamingPublisher;
    private LoggerInterface $logger;

    public function __construct(
        StreamingSessionManager $sessionManager,
        StreamingPublisher $streamingPublisher,
        LoggerInterface $logger
    ) {
        $this->sessionManager = $sessionManager;
        $this->streamingPublisher = $streamingPublisher;
        $this->logger = $logger;
    }

    /**
     * Verarbeitet eine StartStreamingSessionMessage.
     */
    public function __invoke(StartStreamingSessionMessage $message): void
    {
        $sessionId = $message->getSessionId();
        $toolName = $message->getToolName();
        $initialArguments = $message->getInitialArguments();
        $userIdentifier = $message->getUserIdentifier();
        $correlationId = $message->getCorrelationId();

        $this->logger->info('Streaming-Session gestartet', [
            'session_id' => $sessionId,
            'tool_name' => $toolName,
            'user_identifier' => $userIdentifier,
            'correlation_id' => $correlationId,
        ]);

        try {
            // Session aktualisieren (falls noch nicht gestartet)
            $session = $this->sessionManager->getSession($sessionId);
            if ($session !== null && !$session->isActive()) {
                $this->sessionManager->startSession($sessionId);
            }

            // Start-Event an Mercure senden
            $this->streamingPublisher->publishSessionStart(
                $sessionId,
                $toolName,
                $initialArguments,
                $userIdentifier
            );

            $this->logger->debug('Streaming-Session Start-Event veröffentlicht', [
                'session_id' => $sessionId,
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Fehler beim Starten der Streaming-Session', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);

            // Fehler-Event senden
            $this->streamingPublisher->publishError(
                $sessionId,
                'Fehler beim Starten der Session: ' . $e->getMessage()
            );
        }
    }
}
