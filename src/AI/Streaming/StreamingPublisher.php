<?php
// src/AI/Streaming/StreamingPublisher.php

namespace App\AI\Streaming;

use App\Message\StreamToolResponseMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Publisher für Streaming-Updates.
 * Sendet Streaming-Chunks an Mercure Topics.
 */
class StreamingPublisher
{
    private HubInterface $mercureHub;
    private LoggerInterface $logger;

    public function __construct(HubInterface $mercureHub, LoggerInterface $logger)
    {
        $this->mercureHub = $mercureHub;
        $this->logger = $logger;
    }

    /**
     * Sendet ein Streaming-Update an einen Mercure Topic.
     */
    public function publishUpdate(
        string $sessionId,
        string $eventType,
        array $data,
        string $topicSuffix = ''
    ): void {
        $topic = $this->getTopicName($sessionId, $topicSuffix);
        $update = new Update(
            $topic,
            json_encode([
                'event' => $eventType,
                'data' => $data,
                'timestamp' => (new \DateTimeImmutable())->format('c'),
            ])
        );

        try {
            $this->mercureHub->publish($update);
            $this->logger->debug('Streaming-Update veröffentlicht', [
                'topic' => $topic,
                'event' => $eventType,
                'session_id' => $sessionId,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Fehler beim Veröffentlichen des Streaming-Updates', [
                'topic' => $topic,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Sendet eine StreamToolResponseMessage an einen Mercure Topic.
     */
    public function publishStreamResponse(StreamToolResponseMessage $message): void
    {
        $sessionId = $message->getSessionId();
        $eventType = $this->getEventTypeFromChunkType($message->getChunkType());

        $this->publishUpdate(
            $sessionId,
            $eventType,
            $message->toArray()
        );
    }

    /**
     * Sendet ein Start-Event für eine neue Streaming-Session.
     */
    public function publishSessionStart(
        string $sessionId,
        string $toolName,
        array $initialArguments,
        string $userIdentifier
    ): void {
        $this->publishUpdate($sessionId, 'session_start', [
            'session_id' => $sessionId,
            'tool_name' => $toolName,
            'initial_arguments' => $initialArguments,
            'user_identifier' => $userIdentifier,
            'status' => 'running',
            'progress' => 0,
        ]);
    }

    /**
     * Sendet ein End-Event für eine Streaming-Session.
     */
    public function publishSessionEnd(
        string $sessionId,
        string $toolName,
        bool $success,
        string $finalStatus,
        array $metadata = []
    ): void {
        $this->publishUpdate($sessionId, 'session_end', [
            'session_id' => $sessionId,
            'tool_name' => $toolName,
            'success' => $success,
            'final_status' => $finalStatus,
            'metadata' => $metadata,
            'progress' => 100,
        ]);
    }

    /**
     * Sendet ein Progress-Update.
     */
    public function publishProgress(
        string $sessionId,
        float $percentage,
        string $message,
        mixed $data = null
    ): void {
        $this->publishUpdate($sessionId, 'progress', [
            'session_id' => $sessionId,
            'percentage' => $percentage,
            'message' => $message,
            'data' => $data,
        ]);
    }

    /**
     * Sendet ein Error-Event.
     */
    public function publishError(
        string $sessionId,
        string $errorMessage,
        array $errorDetails = []
    ): void {
        $this->publishUpdate($sessionId, 'error', [
            'session_id' => $sessionId,
            'error' => $errorMessage,
            'details' => $errorDetails,
        ]);
    }

    /**
     * Gibt den Topic-Namen für eine Session zurück.
     */
    private function getTopicName(string $sessionId, string $suffix = ''): string
    {
        $topic = sprintf('/streaming/sessions/%s', $sessionId);
        if (!empty($suffix)) {
            $topic .= '/' . $suffix;
        }
        return $topic;
    }

    /**
     * Konvertiert Chunk-Type zu Event-Type.
     */
    private function getEventTypeFromChunkType(string $chunkType): string
    {
        $mapping = [
            'progress' => 'progress',
            'partial_result' => 'partial_result',
            'final_result' => 'final_result',
            'error' => 'error',
            'data' => 'data',
            'log' => 'log',
            'status' => 'status',
        ];

        return $mapping[$chunkType] ?? 'data';
    }
}
