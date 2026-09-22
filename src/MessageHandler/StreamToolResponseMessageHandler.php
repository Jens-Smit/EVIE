<?php
// src/MessageHandler/StreamToolResponseMessageHandler.php

namespace App\MessageHandler;

use App\AI\Streaming\StreamingSessionManager;
use App\Message\StreamToolResponseMessage;
use App\AI\Streaming\StreamingPublisher;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handler für StreamToolResponseMessage.
 * Verarbeitet Streaming-Chunks, aktualisiert den Session-Status
 * und veröffentlicht die Chunks über den StreamingPublisher an Mercure.
 */
#[AsMessageHandler]
class StreamToolResponseMessageHandler
{
    private StreamingSessionManager $sessionManager;
    private LoggerInterface $logger;
    private StreamingPublisher $streamingPublisher;

    public function __construct(
        StreamingSessionManager $sessionManager,
        LoggerInterface $logger,
        StreamingPublisher $streamingPublisher
    ) {
        $this->sessionManager = $sessionManager;
        $this->logger = $logger;
        $this->streamingPublisher = $streamingPublisher;
    }

    /**
     * Verarbeitet eine StreamToolResponseMessage.
     */
    public function __invoke(StreamToolResponseMessage $message): void
    {
        $sessionId = $message->getSessionId();
        $toolName = $message->getToolName();
        $chunk = $message->getChunk();
        $chunkType = $message->getChunkType();
        $isFinal = $message->isFinal();
        $correlationId = $message->getCorrelationId();

        $this->logger->debug('Verarbeite StreamToolResponseMessage', [
            'session_id' => $sessionId,
            'tool_name' => $toolName,
            'chunk_type' => $chunkType,
            'is_final' => $isFinal,
            'correlation_id' => $correlationId,
        ]);

        try {
            // 1. Session aktualisieren (falls vorhanden)
            $session = $this->sessionManager->getSession($sessionId);
            
            if ($session !== null) {
                // Aktualisiere Fortschritt für Progress-Chunks
                if ($chunkType === 'progress' && isset($chunk['percentage'])) {
                    $this->sessionManager->updateProgress(
                        $sessionId,
                        $chunk['percentage'],
                        $chunk['message'] ?? 'Fortschritt',
                        $chunk
                    );
                }

                // Füge Teilergebnisse hinzu
                if ($chunkType === 'partial_result') {
                    $this->sessionManager->addPartialResult($sessionId, $chunk);
                }
            }

            // 2. Chunk an den Client publishen (Mercure)
            $this->processChunk($message);

            // 3. Bei finalem Chunk: Session abschließen (falls noch nicht geschehen)
            if ($isFinal) {
                $this->logger->info('Finaler Streaming-Chunk empfangen', [
                    'session_id' => $sessionId,
                    'tool_name' => $toolName,
                ]);
            }

        } catch (\Exception $e) {
            $this->logger->error('Fehler bei der Verarbeitung des Streaming-Chunks', [
                'session_id' => $sessionId,
                'tool_name' => $toolName,
                'error' => $e->getMessage(),
            ]);

            // Bei Fehler: Session als fehlgeschlagen markieren
            if ($session !== null) {
                $this->sessionManager->failSession(
                    $sessionId,
                    'Fehler bei der Chunk-Verarbeitung: ' . $e->getMessage(),
                    ['exception' => get_class($e)],
                    $correlationId
                );
            }
        }
    }

    /**
     * Verarbeitet einen Chunk und veröffentlicht ihn an den Mercure-Topic der Session.
     */
    private function processChunk(StreamToolResponseMessage $message): void
    {
        $sessionId = $message->getSessionId();
        $chunkType = $message->getChunkType();

        $this->streamingPublisher->publishStreamResponse($message);

        $this->logger->debug('Streaming-Chunk verarbeitet', [
            'session_id' => $sessionId,
            'chunk_type' => $chunkType,
            'chunk_size' => strlen(json_encode($message->getChunk())),
        ]);
    }
}
