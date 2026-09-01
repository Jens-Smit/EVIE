<?php
// src/Controller/Frontend/StreamingController.php

namespace App\Controller\Frontend;

use App\AI\Streaming\StreamingSessionManager;
use App\Repository\StreamingSessionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Frontend-Controller für Streaming-Sessions.
 * Bietet Web-Interfaces für die Verwaltung von Streaming-Sessions.
 */
class StreamingController extends AbstractController
{
    public function __construct(
        private StreamingSessionManager $sessionManager,
        private StreamingSessionRepository $sessionRepo
    ) {
    }

    /**
     * Zeigt die Streaming-Sessions-Übersicht an.
     */
    #[Route('/streaming/sessions', name: 'app_streaming_sessions', methods: ['GET'])]
    public function listSessions(
        Request $request,
        #[CurrentUser] ?UserInterface $user = null
    ): Response
    {
        if (null === $user) {
            return $this->redirectToRoute('app_login');
        }

        $userIdentifier = $user->getUserIdentifier();
        $sessions = $this->sessionManager->getSessionsByUser($userIdentifier);
        $activeSessions = $this->sessionManager->getActiveSessionsByUser($userIdentifier);

        return $this->render('streaming/index.html.twig', [
            'sessions' => $sessions,
            'activeSessions' => $activeSessions,
            'userIdentifier' => $userIdentifier,
        ]);
    }

    /**
     * Zeigt die Details einer bestimmten Streaming-Session an.
     */
    #[Route('/streaming/sessions/{sessionId}', name: 'app_streaming_session_show', methods: ['GET'])]
    public function showSession(
        string $sessionId,
        #[CurrentUser] ?UserInterface $user = null
    ): Response
    {
        if (null === $user) {
            return $this->redirectToRoute('app_login');
        }

        $session = $this->sessionManager->getSession($sessionId);

        if (!$session) {
            throw $this->createNotFoundException('Streaming-Session nicht gefunden');
        }

        // Prüfe, ob der User Zugriff auf diese Session hat
        if ($session->getUserIdentifier() !== $user->getUserIdentifier()) {
            throw $this->createAccessDeniedException('Kein Zugriff auf diese Session');
        }

        return $this->render('streaming/show.html.twig', [
            'session' => $session,
        ]);
    }

    /**
     * Zeigt das Formular zum Starten einer neuen Streaming-Session an.
     */
    #[Route('/streaming/sessions/new', name: 'app_streaming_session_new', methods: ['GET'])]
    public function newSession(
        #[CurrentUser] ?UserInterface $user = null
    ): Response
    {
        if (null === $user) {
            return $this->redirectToRoute('app_login');
        }

        return $this->render('streaming/new.html.twig');
    }
}
