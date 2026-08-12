<?php
// src/AI/Streaming/StreamingSessionManager.php

namespace App\AI\Streaming;

use App\Entity\StreamingSession;
use App\Repository\StreamingSessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Manager für Streaming-Sessions.
 * Verwaltet den Lebenszyklus von Streaming-Sessions und speichert Metadaten.
 */
class StreamingSessionManager
{
    private EntityManagerInterface $entityManager;
    private StreamingSessionRepository $sessionRepo;
    private LoggerInterface $logger;

    public function __construct(
        EntityManagerInterface $entityManager,
        StreamingSessionRepository $sessionRepo,
        LoggerInterface $logger
    ) {
        $this->entityManager = $entityManager;
        $this->sessionRepo = $sessionRepo;
        $this->logger = $logger;
    }

    /**
     * Erstellt eine neue Streaming-Session.
     */
    public function createSession(
        string $toolName,
        array $initialArguments,
        string $userIdentifier,
        ?Uuid $userId = null
    ): StreamingSession {
        $session = new StreamingSession();
        $session->setToolName($toolName);
        $session->setInitialArguments($initialArguments);
        $session->setUserIdentifier($userIdentifier);
        
        if ($userId !== null) {
            $user = $this->entityManager->getRepository('App\Entity\User')->find($userId);
            if ($user !== null) {
                $session->setUser($user);
            }
        }

        $this->entityManager->persist($session);
        $this->entityManager->flush();

        $this->logger->info('Streaming-Session erstellt', [
            'session_id' => $session->getSessionId(),
            'tool_name' => $toolName,
            'user_identifier' => $userIdentifier,
        ]);

        return $session;
    }

    /**
     * Startet eine Streaming-Session.
     */
    public function startSession(string $sessionId): ?StreamingSession
    {
        $session = $this->sessionRepo->findOneBySessionId($sessionId);

        if ($session === null) {
            $this->logger->warning('Streaming-Session nicht gefunden', [
                'session_id' => $sessionId,
            ]);
            return null;
        }

        $session->setStatus(StreamingSession::STATUS_RUNNING);
        $session->setStartedAt(new \DateTimeImmutable());
        $session->setCurrentProgress('Tool-Ausführung gestartet');
        $session->setProgressPercentage(0.0);

        $this->entityManager->flush();

        $this->logger->info('Streaming-Session gestartet', [
            'session_id' => $sessionId,
            'tool_name' => $session->getToolName(),
        ]);

        return $session;
    }

    /**
     * Aktualisiert den Fortschritt einer Streaming-Session.
     */
    public function updateProgress(
        string $sessionId,
        float $percentage,
        string $message,
        mixed $partialResult = null
    ): ?StreamingSession {
        $session = $this->sessionRepo->findOneBySessionId($sessionId);

        if ($session === null) {
            $this->logger->warning('Streaming-Session nicht gefunden für Fortschrittsupdate', [
                'session_id' => $sessionId,
            ]);
            return null;
        }

        $session->setProgressPercentage($percentage);
        $session->setCurrentProgress($message);

        if ($partialResult !== null) {
            $session->addPartialResult($partialResult);
        }

        $this->entityManager->flush();

        $this->logger->debug('Streaming-Session Fortschritt aktualisiert', [
            'session_id' => $sessionId,
            'percentage' => $percentage,
            'message' => $message,
        ]);

        return $session;
    }

    /**
     * Beendet eine Streaming-Session erfolgreich.
     */
    public function completeSession(
        string $sessionId,
        array $finalResult,
        string $correlationId = null
    ): ?StreamingSession {
        $session = $this->sessionRepo->findOneBySessionId($sessionId);

        if ($session === null) {
            $this->logger->warning('Streaming-Session nicht gefunden für Abschluss', [
                'session_id' => $sessionId,
            ]);
            return null;
        }

        $session->setStatus(StreamingSession::STATUS_COMPLETED);
        $session->setFinalResult($finalResult);
        $session->setCompletedAt(new \DateTimeImmutable());
        $session->setProgressPercentage(100.0);
        $session->setCurrentProgress('Abgeschlossen');

        if ($correlationId !== null) {
            $session->setCorrelationId($correlationId);
        }

        $this->entityManager->flush();

        $this->logger->info('Streaming-Session erfolgreich abgeschlossen', [
            'session_id' => $sessionId,
            'tool_name' => $session->getToolName(),
            'duration' => $session->getDuration(),
        ]);

        return $session;
    }

    /**
     * Beendet eine Streaming-Session mit einem Fehler.
     */
    public function failSession(
        string $sessionId,
        string $errorMessage,
        array $errorDetails = [],
        string $correlationId = null
    ): ?StreamingSession {
        $session = $this->sessionRepo->findOneBySessionId($sessionId);

        if ($session === null) {
            $this->logger->warning('Streaming-Session nicht gefunden für Fehlerbehandlung', [
                'session_id' => $sessionId,
            ]);
            return null;
        }

        $session->setStatus(StreamingSession::STATUS_FAILED);
        $session->setErrorData([
            'message' => $errorMessage,
            'details' => $errorDetails,
            'timestamp' => (new \DateTimeImmutable())->format('c'),
        ]);
        $session->setCompletedAt(new \DateTimeImmutable());
        $session->setCurrentProgress('Fehlgeschlagen: ' . $errorMessage);

        if ($correlationId !== null) {
            $session->setCorrelationId($correlationId);
        }

        $this->entityManager->flush();

        $this->logger->error('Streaming-Session fehlgeschlagen', [
            'session_id' => $sessionId,
            'tool_name' => $session->getToolName(),
            'error' => $errorMessage,
            'duration' => $session->getDuration(),
        ]);

        return $session;
    }

    /**
     * Bricht eine Streaming-Session ab.
     */
    public function cancelSession(
        string $sessionId,
        string $reason = 'User cancelled',
        string $correlationId = null
    ): ?StreamingSession {
        $session = $this->sessionRepo->findOneBySessionId($sessionId);

        if ($session === null) {
            $this->logger->warning('Streaming-Session nicht gefunden für Abbruch', [
                'session_id' => $sessionId,
            ]);
            return null;
        }

        $session->setStatus(StreamingSession::STATUS_CANCELLED);
        $session->setErrorData([
            'reason' => $reason,
            'timestamp' => (new \DateTimeImmutable())->format('c'),
        ]);
        $session->setCompletedAt(new \DateTimeImmutable());
        $session->setCurrentProgress('Abgebrochen: ' . $reason);

        if ($correlationId !== null) {
            $session->setCorrelationId($correlationId);
        }

        $this->entityManager->flush();

        $this->logger->info('Streaming-Session abgebrochen', [
            'session_id' => $sessionId,
            'tool_name' => $session->getToolName(),
            'reason' => $reason,
            'duration' => $session->getDuration(),
        ]);

        return $session;
    }

    /**
     * Fügt ein Teilergebnis zu einer Streaming-Session hinzu.
     */
    public function addPartialResult(string $sessionId, mixed $result): ?StreamingSession
    {
        $session = $this->sessionRepo->findOneBySessionId($sessionId);

        if ($session === null) {
            return null;
        }

        $session->addPartialResult($result);
        $this->entityManager->flush();

        return $session;
    }

    /**
     * Hole eine Streaming-Session nach Session-ID.
     */
    public function getSession(string $sessionId): ?StreamingSession
    {
        return $this->sessionRepo->findOneBySessionId($sessionId);
    }

    /**
     * Hole alle aktiven Streaming-Sessions.
     * @return StreamingSession[]
     */
    public function getActiveSessions(): array
    {
        return $this->sessionRepo->findAllActive();
    }

    /**
     * Hole alle laufenden Streaming-Sessions.
     * @return StreamingSession[]
     */
    public function getRunningSessions(): array
    {
        return $this->sessionRepo->findAllRunning();
    }

    /**
     * Hole alle abgeschlossenen Streaming-Sessions.
     * @return StreamingSession[]
     */
    public function getFinishedSessions(): array
    {
        return $this->sessionRepo->findAllFinished();
    }

    /**
     * Hole Streaming-Sessions nach User.
     * @return StreamingSession[]
     */
    public function getSessionsByUser(string $userIdentifier): array
    {
        return $this->sessionRepo->findByUser($userIdentifier);
    }

    /**
     * Hole aktive Streaming-Sessions nach User.
     * @return StreamingSession[]
     */
    public function getActiveSessionsByUser(string $userIdentifier): array
    {
        return $this->sessionRepo->findActiveByUser($userIdentifier);
    }

    /**
     * Prüfe, ob eine Streaming-Session existiert.
     */
    public function hasSession(string $sessionId): bool
    {
        return $this->sessionRepo->existsBySessionId($sessionId);
    }

    /**
     * Prüfe, ob eine Streaming-Session aktiv ist.
     */
    public function isSessionActive(string $sessionId): bool
    {
        $session = $this->getSession($sessionId);
        return $session !== null && $session->isActive();
    }

    /**
     * Prüfe, ob eine Streaming-Session abgeschlossen ist.
     */
    public function isSessionFinished(string $sessionId): bool
    {
        $session = $this->getSession($sessionId);
        return $session !== null && $session->isFinished();
    }

    /**
     * Lösche alle abgeschlossenen Streaming-Sessions, die älter als $days Tage sind.
     */
    public function cleanupFinishedSessions(int $days = 30): int
    {
        return $this->sessionRepo->deleteFinishedOlderThan($days);
    }

    /**
     * Generiere eine eindeutige Session-ID.
     */
    public function generateSessionId(): string
    {
        return Uuid::v4()->toRfc4122();
    }

    /**
     * Zähle alle aktiven Streaming-Sessions.
     */
    public function countActiveSessions(): int
    {
        return $this->sessionRepo->countActive();
    }

    /**
     * Zähle alle Streaming-Sessions nach Status.
     * @return array<string, int>
     */
    public function countSessionsByStatus(): array
    {
        return $this->sessionRepo->countByStatus();
    }
}
