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
 * Controller fuer die Freigabe und Ablehnung von Tools.
 * Implementiert den Human-in-the-Loop (HITL) Mechanismus fuer Tool-Genehmigung.
 * Unterstuetzt sowohl HTML- als auch AJAX-Anfragen.
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
                'status' => 'success',
                'tools' => array_map(function(ToolDefinition $tool) {
                    return [
                        'id' => $tool->getId(),
                        'name' => $tool->getName(),
                        'description' => $tool->getDescription(),
                        'status' => $tool->getStatus(),
                        'created_at' => $tool->getCreatedAt()?->format(DATE_ATOM),
                        'schema' => $tool->getSchema(),
                        'security_level' => $tool->getSecurityLevel(),
                        'requires_hitl' => $tool->isRequiresHitl(),
                    ];
                }, $pendingTools),
            ]);
        }

        return $this->render('tools/pending_list.html.twig', [
            'pendingTools' => $pendingTools,
        ]);
    }

    /**
     * Zeigt die Details eines ausstehenden Tools.
     */
    #[Route('/tools/pending/{id}', name: 'app_tool_pending_show', methods: ['GET'])]
    public function showPending(ToolDefinition $tool): Response
    {
        if (!in_array($tool->getStatus(), ['pending', 'pending_approval'])) {
            throw $this->createNotFoundException('Tool nicht gefunden oder bereits bearbeitet');
        }

        return $this->render('tools/pending_show.html.twig', [
            'tool' => $tool,
        ]);
    }

    /**
     * Genehmigt ein ausstehendes Tool.
     */
    #[Route('/tools/pending/{id}/approve', name: 'app_tool_pending_approve', methods: ['POST'])]
    public function approveTool(ToolDefinition $tool): JsonResponse
    {
        try {
            // Status auf approved setzen
            $tool->setStatus('approved');
            $tool->setApprovedAt(new \DateTimeImmutable());
            $this->toolDefinitionRepo->save($tool, true);

            // Event ausloesen
            $this->dispatcher->dispatch(new PendingToolApprovalEvent($tool, null, true));

            return $this->json([
                'status' => 'success',
                'message' => 'Tool wurde genehmigt',
                'tool' => [
                    'id' => $tool->getId(),
                    'name' => $tool->getName(),
                    'status' => $tool->getStatus(),
                ],
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Fehler beim Genehmigen des Tools: ' . $e->getMessage());
            return $this->json([
                'status' => 'error',
                'message' => 'Fehler beim Genehmigen des Tools',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Lehnt ein ausstehendes Tool ab.
     */
    #[Route('/tools/pending/{id}/reject', name: 'app_tool_pending_reject', methods: ['POST'])]
    public function rejectTool(Request $request, ToolDefinition $tool): JsonResponse
    {
        try {
            $reason = $request->request->get('reason', '');

            // Status auf rejected setzen
            $tool->setStatus('rejected');
            $tool->setRejectedAt(new \DateTimeImmutable());
            $tool->setRejectionReason($reason);
            $this->toolDefinitionRepo->save($tool, true);

            // Event ausloesen
            $this->dispatcher->dispatch(new PendingToolApprovalEvent($tool, null, false));

            return $this->json([
                'status' => 'success',
                'message' => 'Tool wurde abgelehnt',
                'tool' => [
                    'id' => $tool->getId(),
                    'name' => $tool->getName(),
                    'status' => $tool->getStatus(),
                    'rejection_reason' => $reason,
                ],
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Fehler beim Ablehnen des Tools: ' . $e->getMessage());
            return $this->json([
                'status' => 'error',
                'message' => 'Fehler beim Ablehnen des Tools',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Setzt den Status eines Tools zurueck auf pending.
     */
    #[Route('/tools/pending/{id}/reset', name: 'app_tool_pending_reset', methods: ['POST'])]
    public function resetToolStatus(ToolDefinition $tool): JsonResponse
    {
        try {
            $tool->setStatus('pending');
            $tool->setApprovedAt(null);
            $tool->setRejectedAt(null);
            $tool->setRejectionReason(null);
            $this->toolDefinitionRepo->save($tool, true);

            return $this->json([
                'status' => 'success',
                'message' => 'Tool-Status zurueckgesetzt',
                'tool' => [
                    'id' => $tool->getId(),
                    'name' => $tool->getName(),
                    'status' => $tool->getStatus(),
                ],
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Fehler beim Zuruecksetzen des Tool-Status: ' . $e->getMessage());
            return $this->json([
                'status' => 'error',
                'message' => 'Fehler beim Zuruecksetzen des Tool-Status',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
    
    /**
     * Gibt die Anzahl der ausstehenden Tools zurueck (fuer Sidebar Badge)
     */
    #[Route('/api/pending-tools/count', name: 'app_pending_tools_count', methods: ['GET'])]
    public function getPendingToolsCount(): JsonResponse
    {
        $count = $this->toolDefinitionRepo->count([
            'status' => ['pending', 'pending_approval'],
        ]);
        
        return $this->json([
            'count' => $count,
        ]);
    }
}
