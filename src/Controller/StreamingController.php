<?php
// src/Controller/StreamingController.php

namespace App\Controller;

use App\AI\Streaming\StreamingSessionManager;
use App\Message\ExecuteToolMessage;
use App\Repository\StreamingSessionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Controller für Streaming-Antworten.
 * Bietet Endpoints zum Starten, Überwachen und Verwalten von Streaming-Sessions.
 */
class StreamingController extends AbstractController
{
    private StreamingSessionManager $sessionManager;
    private StreamingSessionRepository $sessionRepo;
    private MessageBusInterface $messageBus;

    public function __construct(
        StreamingSessionManager $sessionManager,
        StreamingSessionRepository $sessionRepo,
        MessageBusInterface $messageBus
    ) {
        $this->sessionManager = $sessionManager;
        $this->sessionRepo = $sessionRepo;
        $this->messageBus = $messageBus;
    }

    /**
     * Startet eine neue Streaming-Session für ein Tool.
     */
    #[Route('/api/streaming/sessions', name: 'api_streaming_sessions_create', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function createSession(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->json(['error' => 'Ungültiges JSON'], 400);
        }

        if (!isset($data['tool_name']) || !isset($data['arguments'])) {
            return $this->json(['error' => 'tool_name und arguments sind erforderlich'], 400);
        }

        $toolName = $data['tool_name'];
        $arguments = $data['arguments'];
        $userIdentifier = $this->getUser()?->getUserIdentifier() ?? 'anonymous';

        // Session erstellen
        $session = $this->sessionManager->createSession(
            $toolName,
            $arguments,
            $userIdentifier,
            $this->getUser()?->getId()
        );

        // ExecuteToolMessage senden (asynchron)
        $this->messageBus->dispatch(new ExecuteToolMessage(
            $toolName,
            $arguments,
            $userIdentifier,
            $session->getSessionId()
        ));

        return $this->json([
            'status' => 'created',
            'session_id' => $session->getSessionId(),
            'tool_name' => $toolName,
            'created_at' => $session->getCreatedAt()->format('c'),
            'message' => 'Streaming-Session gestartet. Ergebnisse werden gestreamt.',
        ], 202); // 202 Accepted
    }

    /**
     * Hole den Status einer Streaming-Session.
     */
    #[Route('/api/streaming/sessions/{sessionId}', name: 'api_streaming_session_status', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function getSessionStatus(string $sessionId): JsonResponse
    {
        $session = $this->sessionManager->getSession($sessionId);

        if ($session === null) {
            return $this->json(['error' => 'Session nicht gefunden'], 404);
        }

        // Prüfe, ob der User Zugriff auf diese Session hat
        if ($session->getUserIdentifier() !== $this->getUser()?->getUserIdentifier()) {
            return $this->json(['error' => 'Kein Zugriff auf diese Session'], 403);
        }

        return $this->json($session->toArray());
    }

    /**
     * Hole alle Streaming-Sessions eines Users.
     */
    #[Route('/api/streaming/sessions', name: 'api_streaming_sessions_list', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function listSessions(): JsonResponse
    {
        $userIdentifier = $this->getUser()?->getUserIdentifier() ?? 'anonymous';
        $sessions = $this->sessionManager->getSessionsByUser($userIdentifier);

        $sessionsArray = [];
        foreach ($sessions as $session) {
            $sessionsArray[] = $session->toArray();
        }

        return $this->json([
            'sessions' => $sessionsArray,
            'count' => count($sessionsArray),
        ]);
    }

    /**
     * Hole alle aktiven Streaming-Sessions eines Users.
     */
    #[Route('/api/streaming/sessions/active', name: 'api_streaming_sessions_active', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function listActiveSessions(): JsonResponse
    {
        $userIdentifier = $this->getUser()?->getUserIdentifier() ?? 'anonymous';
        $sessions = $this->sessionManager->getActiveSessionsByUser($userIdentifier);

        $sessionsArray = [];
        foreach ($sessions as $session) {
            $sessionsArray[] = $session->toArray();
        }

        return $this->json([
            'sessions' => $sessionsArray,
            'count' => count($sessionsArray),
        ]);
    }

    /**
     * Bricht eine Streaming-Session ab.
     */
    #[Route('/api/streaming/sessions/{sessionId}/cancel', name: 'api_streaming_session_cancel', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function cancelSession(string $sessionId): JsonResponse
    {
        $session = $this->sessionManager->getSession($sessionId);

        if ($session === null) {
            return $this->json(['error' => 'Session nicht gefunden'], 404);
        }

        // Prüfe, ob der User Zugriff auf diese Session hat
        if ($session->getUserIdentifier() !== $this->getUser()?->getUserIdentifier()) {
            return $this->json(['error' => 'Kein Zugriff auf diese Session'], 403);
        }

        if (!$session->isActive()) {
            return $this->json(['error' => 'Session ist nicht aktiv'], 400);
        }

        $this->sessionManager->cancelSession($sessionId, 'User cancelled via API');

        return $this->json([
            'status' => 'cancelled',
            'session_id' => $sessionId,
            'message' => 'Session erfolgreich abgebrochen',
        ]);
    }

    /**
     * Löscht eine Streaming-Session.
     */
    #[Route('/api/streaming/sessions/{sessionId}', name: 'api_streaming_session_delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_USER')]
    public function deleteSession(string $sessionId): JsonResponse
    {
        $session = $this->sessionManager->getSession($sessionId);

        if ($session === null) {
            return $this->json(['error' => 'Session nicht gefunden'], 404);
        }

        // Prüfe, ob der User Zugriff auf diese Session hat
        if ($session->getUserIdentifier() !== $this->getUser()?->getUserIdentifier()) {
            return $this->json(['error' => 'Kein Zugriff auf diese Session'], 403);
        }

        $this->entityManager->remove($session);
        $this->entityManager->flush();

        return $this->json([
            'status' => 'deleted',
            'session_id' => $sessionId,
            'message' => 'Session erfolgreich gelöscht',
        ]);
    }

    /**
     * Hole Statistiken zu Streaming-Sessions.
     */
    #[Route('/api/streaming/stats', name: 'api_streaming_stats', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function getStats(): JsonResponse
    {
        $counts = $this->sessionManager->countSessionsByStatus();
        $activeCount = $this->sessionManager->countActiveSessions();

        return $this->json([
            'total' => array_sum($counts),
            'active' => $activeCount,
            'by_status' => $counts,
        ]);
    }

    /**
     * Bereinigt abgeschlossene Streaming-Sessions.
     */
    #[Route('/api/streaming/sessions/cleanup', name: 'api_streaming_sessions_cleanup', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function cleanupSessions(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $days = $data['days'] ?? 30;

        $deletedCount = $this->sessionManager->cleanupFinishedSessions($days);

        return $this->json([
            'status' => 'cleanup_complete',
            'deleted_count' => $deletedCount,
            'days' => $days,
            'message' => sprintf('%d abgeschlossene Sessions gelöscht', $deletedCount),
        ]);
    }

    /**
     * Endpoint für SSE (Server-Sent Events) Streaming.
     * Clients können diesen Endpoint abonnieren, um Streaming-Updates zu erhalten.
     */
    #[Route('/api/streaming/sessions/{sessionId}/stream', name: 'api_streaming_session_stream', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function streamSession(string $sessionId, Request $request): Response
    {
        $session = $this->sessionManager->getSession($sessionId);

        if ($session === null) {
            return $this->json(['error' => 'Session nicht gefunden'], 404);
        }

        // Prüfe, ob der User Zugriff auf diese Session hat
        if ($session->getUserIdentifier() !== $this->getUser()?->getUserIdentifier()) {
            return $this->json(['error' => 'Kein Zugriff auf diese Session'], 403);
        }

        // In einer echten Implementierung:
        // 1. Mercure Topic abonnieren
        // 2. SSE-Stream zurückgeben
        
        // Für jetzt: Einfache Implementierung mit Polling-Hinweis
        // In Produktion: Mercure oder WebSocket Integration

        $response = new Response();
        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('Connection', 'keep-alive');
        $response->headers->set('X-Accel-Buffering', 'no');

        // Sende initialen Status
        $response->setContent($this->createSSEData('status', $session->toArray()));

        return $response;
    }

    /**
     * Erstellt SSE-Daten.
     */
    private function createSSEData(string $event, array $data): string
    {
        return sprintf("event: %s\ndata: %s\n\n", $event, json_encode($data));
    }
}
