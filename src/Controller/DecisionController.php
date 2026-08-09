<?php
// src/Controller/DecisionController.php

namespace App\Controller;

use App\AI\Decision\DecisionManager;
use App\Entity\DecisionLog;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Controller für Entscheidungs-Freigaben.
 * Bietet API-Endpunkte für das Listieren, Genehmigen und Ablehnen von Entscheidungen.
 */
class DecisionController extends AbstractController
{
    public function __construct(
        private DecisionManager $decisionManager
    ) {
    }

    /**
     * Listet alle ausstehenden Entscheidungen auf
     */
    #[Route('/api/decisions/pending', name: 'api_decisions_pending', methods: ['GET'])]
    public function listPendingDecisions(#[CurrentUser] ?object $user = null): JsonResponse
    {
        $userIdentifier = $user?->getUserIdentifier() ?? 'default_user';

        $decisions = $this->decisionManager->getPendingDecisions($userIdentifier);

        return $this->json([
            'status' => 'success',
            'count' => count($decisions),
            'decisions' => $decisions,
        ]);
    }

    /**
     * Listet alle Entscheidungen eines bestimmten Typs auf
     */
    #[Route('/api/decisions/type/{type}', name: 'api_decisions_by_type', methods: ['GET'])]
    public function listDecisionsByType(
        string $type,
        #[CurrentUser] ?object $user = null
    ): JsonResponse {
        $userIdentifier = $user?->getUserIdentifier() ?? 'default_user';

        $decisions = $this->decisionManager->getDecisionsByType($type, $userIdentifier);

        return $this->json([
            'status' => 'success',
            'type' => $type,
            'count' => count($decisions),
            'decisions' => $decisions,
        ]);
    }

    /**
     * Gibt die neuesten Entscheidungen zurück
     */
    #[Route('/api/decisions/recent', name: 'api_decisions_recent', methods: ['GET'])]
    public function listRecentDecisions(
        Request $request,
        #[CurrentUser] ?object $user = null
    ): JsonResponse {
        $userIdentifier = $user?->getUserIdentifier() ?? 'default_user';
        $limit = $request->query->getInt('limit', 10);

        $decisions = $this->decisionManager->getRecentDecisions($limit, $userIdentifier);

        return $this->json([
            'status' => 'success',
            'count' => count($decisions),
            'decisions' => $decisions,
        ]);
    }

    /**
     * Gibt Statistiken über Entscheidungen zurück
     */
    #[Route('/api/decisions/statistics', name: 'api_decisions_statistics', methods: ['GET'])]
    public function decisionStatistics(#[CurrentUser] ?object $user = null): JsonResponse
    {
        $userIdentifier = $user?->getUserIdentifier() ?? 'default_user';

        $statistics = $this->decisionManager->getDecisionStatistics($userIdentifier);

        return $this->json([
            'status' => 'success',
            'statistics' => $statistics,
        ]);
    }

    /**
     * Genehmigt eine Entscheidung
     */
    #[Route('/api/decisions/{id}/approve', name: 'api_decisions_approve', methods: ['POST'])]
    public function approveDecision(
        int $id,
        Request $request,
        #[CurrentUser] ?object $user = null
    ): JsonResponse {
        $userIdentifier = $user?->getUserIdentifier() ?? 'default_user';
        $decision = $this->decisionManager->getDecision($id);

        if (!$decision) {
            return $this->json([
                'status' => 'error',
                'message' => 'Entscheidung nicht gefunden',
            ], 404);
        }

        if (!$decision->isPending()) {
            return $this->json([
                'status' => 'error',
                'message' => 'Entscheidung ist nicht mehr ausstehend',
            ], 400);
        }

        $this->decisionManager->approveDecision($decision, $userIdentifier);

        return $this->json([
            'status' => 'success',
            'message' => 'Entscheidung genehmigt',
            'decision_id' => $id,
        ]);
    }

    /**
     * Lehnt eine Entscheidung ab
     */
    #[Route('/api/decisions/{id}/reject', name: 'api_decisions_reject', methods: ['POST'])]
    public function rejectDecision(
        int $id,
        Request $request,
        #[CurrentUser] ?object $user = null
    ): JsonResponse {
        $userIdentifier = $user?->getUserIdentifier() ?? 'default_user';
        $decision = $this->decisionManager->getDecision($id);

        if (!$decision) {
            return $this->json([
                'status' => 'error',
                'message' => 'Entscheidung nicht gefunden',
            ], 404);
        }

        if (!$decision->isPending()) {
            return $this->json([
                'status' => 'error',
                'message' => 'Entscheidung ist nicht mehr ausstehend',
            ], 400);
        }

        // Reason aus dem Request-Body extrahieren
        $data = json_decode($request->getContent(), true);
        $reason = $data['reason'] ?? $request->request->get('reason', null);

        $this->decisionManager->rejectDecision($decision, $userIdentifier, $reason);

        return $this->json([
            'status' => 'success',
            'message' => 'Entscheidung abgelehnt',
            'decision_id' => $id,
            'reason' => $reason,
        ]);
    }

    /**
     * Gibt eine bestimmte Entscheidung zurück
     */
    #[Route('/api/decisions/{id}', name: 'api_decisions_show', methods: ['GET'])]
    public function showDecision(int $id, #[CurrentUser] ?object $user = null): JsonResponse
    {
        $decision = $this->decisionManager->getDecision($id);

        if (!$decision) {
            return $this->json([
                'status' => 'error',
                'message' => 'Entscheidung nicht gefunden',
            ], 404);
        }

        return $this->json([
            'status' => 'success',
            'decision' => [
                'id' => $decision->getId(),
                'type' => $decision->getDecisionType(),
                'description' => $decision->getDescription(),
                'status' => $decision->getStatus(),
                'context' => $decision->getContext(),
                'options' => $decision->getOptions(),
                'created_at' => $decision->getCreatedAt()->format('Y-m-d H:i:s'),
                'approved_at' => $decision->getApprovedAt()?->format('Y-m-d H:i:s'),
                'approved_by' => $decision->getApprovedBy(),
                'metadata' => $decision->getMetadata(),
            ],
        ]);
    }

    /**
     * Prüft, ob ausstehende Entscheidungen vorhanden sind
     */
    #[Route('/api/decisions/check', name: 'api_decisions_check', methods: ['GET'])]
    public function checkPendingDecisions(#[CurrentUser] ?object $user = null): JsonResponse
    {
        $userIdentifier = $user?->getUserIdentifier() ?? 'default_user';

        $hasPending = $this->decisionManager->hasPendingDecisions($userIdentifier);
        $count = $this->decisionManager->countPendingDecisions($userIdentifier);

        return $this->json([
            'status' => 'success',
            'has_pending' => $hasPending,
            'count' => $count,
        ]);
    }

    /**
     * Zeigt die Entscheidungs-Übersicht an
     */
    #[Route('/decisions', name: 'app_decisions', methods: ['GET'])]
    public function decisionsDashboard(#[CurrentUser] ?object $user = null): Response
    {
        $userIdentifier = $user?->getUserIdentifier() ?? 'default_user';

        $pendingDecisions = $this->decisionManager->getPendingDecisions($userIdentifier);
        $recentDecisions = $this->decisionManager->getRecentDecisions(10, $userIdentifier);
        $statistics = $this->decisionManager->getDecisionStatistics($userIdentifier);

        return $this->render('decision/dashboard.html.twig', [
            'pendingDecisions' => $pendingDecisions,
            'recentDecisions' => $recentDecisions,
            'statistics' => $statistics,
            'userIdentifier' => $userIdentifier,
        ]);
    }
}
