<?php
// src/MessageHandler/EndStreamingSessionMessageHandler.php

namespace App\MessageHandler;

use App\AI\Streaming\StreamingPublisher;
use App\AI\Streaming\StreamingSessionManager;
use App\Message\EndStreamingSessionMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handler für EndStreamingSessionMessage.
 * Beendet eine Streaming-Session und benachrichtigt Clients.
 */
#[AsMessageHandler]
class EndStreamingSessionMessageHandler
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
     * Verarbeitet eine EndStreamingSessionMessage.
     */
    public function __invoke(EndStreamingSessionMessage $message): void
    {
        $sessionId = $message->getSessionId();
        $toolName = $message->getToolName();
        $success = $message->isSuccess();
        $finalStatus = $message->getFinalStatus();
        $metadata = $message->getMetadata();
        $correlationId = $message->getCorrelationId();

        $this->logger->info('Streaming-Session beendet', [
            'session_id' => $sessionId,
            'tool_name' => $toolName,
            'success' => $success,
            'final_status' => $finalStatus,
            'correlation_id' => $correlationId,
        ]);

        try {
            // End-Event an Mercure senden
            $this->streamingPublisher->publishSessionEnd(
                $sessionId,
                $toolName,
                $success,
                $finalStatus,
                $metadata
            );

            $this->logger->debug('Streaming-Session End-Event veröffentlicht', [
                'session_id' => $sessionId,
                'final_status' => $finalStatus,
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Fehler beim Beenden der Streaming-Session', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
