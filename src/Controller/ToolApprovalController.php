<?php
// src/Controller/ToolApprovalController.php

namespace App\Controller;

use App\Entity\ToolDefinition;
use App\Repository\ToolDefinitionRepository;
use App\Event\PendingToolApprovalEvent;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Controller für die Freigabe und Ablehnung von Tools.
 * Implementiert den Human-in-the-Loop (HITL) Mechanismus für Tool-Genehmigung.
 * Unterstützt sowohl HTML- als auch AJAX-Anfragen.
 */
final class ToolApprovalController extends AbstractController
{
    public function __construct(
        private ToolDefinitionRepository $toolDefinitionRepo,
        private EventDispatcherInterface $dispatcher,
        private UrlGeneratorInterface $urlGenerator,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Liste aller ausstehenden Tool-Freigaben.
     */
    #[Route('/tools/pending', name: 'app_tool_pending_list')]
    public function listPending(Request $request): Response
    {
        $pendingTools = $this->toolDefinitionRepo->findBy([
            'status' => ['pending', 'pending_approval'],
        ]);

        // Sortiere nach Erstellungsdatum (neueste zuerst)
        usort($pendingTools, function($a, $b) {
            return $b->getCreatedAt() <=> $a->getCreatedAt();
        });

        if ($request->isXmlHttpRequest() || $request->headers->get('Accept') === 'application/json') {
            return $this->json([
                'success' => true,
                'count' => count($pendingTools),
                'tools' => array_map(function (ToolDefinition $tool) {
                    return [
                        'id' => $tool->getId(),
                        'name' => $tool->getName(),
                        'description' => $tool->getDescription(),
                        'created_at' => $tool->getCreatedAt()?->format('Y-m-d H:i:s'),
                        'schema' => $tool->getSchema(),
                        'approval_url' => $this->urlGenerator->generate('app_tool_approve_api', ['id' => $tool->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
                        'reject_url' => $this->urlGenerator->generate('app_tool_reject_api', ['id' => $tool->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
                        'show_url' => $this->urlGenerator->generate('app_tool_show', ['id' => $tool->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
                    ];
                }, $pendingTools),
            ]);
        }

        // Für HTML-Anfragen: Render Template mit Badge-Count
        return $this->render('tools/pending.html.twig', [
            'pendingTools' => $pendingTools,
            'pending_tools_count' => count($pendingTools),
        ]);
    }

    /**
     * Freigabe oder Ablehnung eines Tools.
     */
    #[Route('/tools/{id}/{action}', name: 'app_tool_approval', requirements: ['action' => 'approve|reject'], methods: ['POST'])]
    public function handleApproval(
        ToolDefinition $toolDefinition,
        string $action,
        Request $request
    ): Response {
        // Prüfe, ob das Tool im richtigen Status ist
        if (!in_array($toolDefinition->getStatus(), ['pending', 'pending_approval'])) {
            return $this->json([
                'success' => false,
                'message' => 'Dieses Tool ist nicht mehr zur Freigabe verfügbar.',
            ], Response::HTTP_BAD_REQUEST);
        }

        // Führe die Aktion aus
        if ($action === 'approve') {
            $toolDefinition->setStatus('approved');
            $toolDefinition->setUpdatedAt(new \DateTimeImmutable());
            
            // DynamicToolbox liest approved Tools live aus der Datenbank (Blueprint §4.B);
            // ein explizites Registry-Update ist nicht erforderlich.
            $this->logger->info('Tool freigegeben', [
                'tool_id' => $toolDefinition->getId(),
                'tool_name' => $toolDefinition->getName(),
            ]);

            $message = 'Tool wurde erfolgreich freigegeben und steht jetzt zur Verfügung!';
        } else {
            $toolDefinition->setStatus('rejected');
            $toolDefinition->setUpdatedAt(new \DateTimeImmutable());
            
            $this->logger->info('Tool abgelehnt', [
                'tool_id' => $toolDefinition->getId(),
                'tool_name' => $toolDefinition->getName(),
            ]);

            $message = 'Tool wurde abgelehnt.';
        }

        // Speichere die Änderungen
        $this->toolDefinitionRepo->save($toolDefinition, true);

        // Dispatch Event für weitere Aktionen (z. B. Benachrichtigung)
        $this->dispatcher->dispatch(new PendingToolApprovalEvent($toolDefinition, 'system'));

        // Antwort basierend auf dem Request-Typ
        if ($request->isXmlHttpRequest() || $request->headers->get('Accept') === 'application/json') {
            return $this->json([
                'success' => true,
                'status' => $action,
                'tool_id' => $toolDefinition->getId(),
                'tool_name' => $toolDefinition->getName(),
                'message' => $message,
            ]);
        }

        // Für HTML-Anfragen: Weiterleitung
        $this->addFlash('success', $message);
        return $this->redirectToRoute('app_tool_pending_list');
    }

    /**
     * Zeigt die Details eines Tools an (für die Freigabe-Oberfläche).
     */
    #[Route('/tools/{id}/show', name: 'app_tool_show')]
    public function showTool(ToolDefinition $toolDefinition): Response
    {
        return $this->render('tools/tool_detail.html.twig', [
            'tool' => $toolDefinition,
            'approval_url' => $this->urlGenerator->generate('app_tool_approve_api', ['id' => $toolDefinition->getId()]),
            'reject_url' => $this->urlGenerator->generate('app_tool_reject_api', ['id' => $toolDefinition->getId()]),
        ]);
    }

    /**
     * API-Endpoint für die Abfrage des Status eines Tools.
     */
    #[Route('/api/tools/{id}/status', name: 'app_tool_status', methods: ['GET'])]
    public function getToolStatus(ToolDefinition $toolDefinition): JsonResponse
    {
        return $this->json([
            'id' => $toolDefinition->getId(),
            'name' => $toolDefinition->getName(),
            'status' => $toolDefinition->getStatus(),
            'description' => $toolDefinition->getDescription(),
            'created_at' => $toolDefinition->getCreatedAt()?->format('c'),
            'updated_at' => $toolDefinition->getUpdatedAt()?->format('c'),
        ]);
    }

    /**
     * API-Endpoint für die Freigabe eines Tools (AJAX).
     */
    #[Route('/api/tools/{id}/approve', name: 'app_tool_approve_api', methods: ['POST'])]
    public function approveToolApi(ToolDefinition $toolDefinition, Request $request): JsonResponse
    {
        return $this->handleApproval($toolDefinition, 'approve', $request);
    }

    /**
     * API-Endpoint für die Ablehnung eines Tools (AJAX).
     */
    #[Route('/api/tools/{id}/reject', name: 'app_tool_reject_api', methods: ['POST'])]
    public function rejectToolApi(ToolDefinition $toolDefinition, Request $request): JsonResponse
    {
        return $this->handleApproval($toolDefinition, 'reject', $request);
    }

    /**
     * Listet alle genehmigten Tools auf.
     */
    #[Route('/api/tools/approved', name: 'app_tool_approved_list', methods: ['GET'])]
    public function listApprovedTools(Request $request): JsonResponse
    {
        try {
            $approvedTools = $this->toolDefinitionRepo->findBy(['status' => 'approved']);
            
            $toolsData = [];
            foreach ($approvedTools as $tool) {
                $toolsData[] = [
                    'id' => $tool->getId(),
                    'name' => $tool->getName(),
                    'description' => $tool->getDescription(),
                    'status' => $tool->getStatus(),
                    'created_at' => $tool->getCreatedAt()?->format('Y-m-d H:i:s'),
                ];
            }

            return $this->json([
                'status' => 'success',
                'count' => count($toolsData),
                'tools' => $toolsData,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Fehler beim Auflisten der genehmigten Tools: ' . $e->getMessage());
            return $this->json([
                'status' => 'error',
                'message' => 'Fehler beim Auflisten der Tools',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Setzt den Status eines Tools zurück auf 'pending' (z. B. für Tests).
     */
    #[Route('/api/tools/{id}/reset', name: 'app_tool_reset', methods: ['POST'])]
    public function resetToolStatus(int $id, Request $request): JsonResponse
    {
        try {
            $tool = $this->toolDefinitionRepo->find($id);
            
            if (!$tool) {
                return $this->json(['error' => 'Tool not found'], Response::HTTP_NOT_FOUND);
            }

            $tool->setStatus('pending');
            $tool->setUpdatedAt(new \DateTimeImmutable());
            $this->toolDefinitionRepo->save($tool, true);

            // DynamicToolbox reflektiert den geänderten Status beim nächsten Aufruf.
            $this->logger->info('Tool-Status zurückgesetzt', [
                'tool_id' => $id,
                'tool_name' => $tool->getName(),
            ]);

            return $this->json([
                'status' => 'success',
                'message' => 'Tool-Status zurückgesetzt',
                'tool' => [
                    'id' => $tool->getId(),
                    'name' => $tool->getName(),
                    'status' => $tool->getStatus(),
                ],
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Fehler beim Zurücksetzen des Tool-Status: ' . $e->getMessage());
            return $this->json([
                'status' => 'error',
                'message' => 'Fehler beim Zurücksetzen des Tool-Status',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
    
    /**
     * Gibt die Anzahl der ausstehenden Tools zurück (für Sidebar Badge)
     */
    #[Route('/api/pending-tools/count', name: 'app_pending_tools_count', methods: ['GET'])]
    public function getPendingToolsCount(): JsonResponse
    {
        try {
            $count = $this->toolDefinitionRepo->count([
                'status' => ['pending', 'pending_approval'],
            ]);
            
            return $this->json([
                'status' => 'success',
                'count' => $count,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Fehler beim Abrufen der ausstehenden Tools: ' . $e->getMessage());
            return $this->json([
                'status' => 'error',
                'message' => 'Fehler beim Abrufen der Count',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
