<?php
// src/Controller/ToolApprovalController.php

namespace App\Controller;

use App\Entity\ToolDefinition;
use App\Repository\ToolDefinitionRepository;
use App\Event\PendingToolApprovalEvent;
use App\Service\SecretService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use Symfony\Component\Routing\Attribute\Route;
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
        private LoggerInterface $logger,
        private SecretService $secretService,
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
                        'requires_hitl' => $tool->getRequiresHitl(),
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
    public function approveTool(Request $request, ToolDefinition $tool): JsonResponse
    {
        try {
            // Freitext-Antwort des Users (Luecke 4): die Antwort wird im
            // metadata-Feld der ToolDefinition gespeichert und ist fuer die
            // Wiederaufnahme des pausierten Plans verfuegbar.
            $userAnswer = trim((string) $request->request->get('user_answer', $request->request->get('answer', '')));

            // Secret hinterlegen (Luecke 5): der User kann waehrend der
            // Freigabe einen API-Key/ein Secret mit Bezeichnung hinterlegen.
            $secretName = trim((string) $request->request->get('secret_name', ''));
            $secretValue = trim((string) $request->request->get('secret_value', ''));
            $secretScope = trim((string) $request->request->get('secret_scope', ''));

            // Status auf approved setzen
            $tool->setStatus('approved');
            $tool->setApprovedAt(new \DateTimeImmutable());

            // Freitext-Antwort in metadata ablegen (fuer Wiederaufnahme).
            $metadata = $tool->getMetadata() ?? [];
            if ($userAnswer !== '') {
                $metadata['user_answer'] = $userAnswer;
            }
            if ($secretName !== '' && $secretValue !== '') {
                $metadata['secret_name'] = $secretName;
            }
            $tool->setMetadata($metadata);
            $this->toolDefinitionRepo->save($tool, true);

            // Secret verschluesselt hinterlegen (Luecke 5).
            if ($secretName !== '' && $secretValue !== '') {
                $userIdentifier = $tool->getUserIdentifier();
                if ($userIdentifier !== null && $userIdentifier !== '') {
                    $this->secretService->set(
                        $secretName,
                        $secretValue,
                        $userIdentifier,
                        $secretScope !== '' ? $secretScope : null,
                    );
                }
            }

            // Event ausloesen (mit userIdentifier fuer Wiederaufnahme).
            $this->dispatcher->dispatch(new PendingToolApprovalEvent(
                $tool,
                $tool->getUserIdentifier(),
                true,
            ));

            $responseData = [
                'status' => 'success',
                'message' => 'Tool wurde genehmigt',
                'tool' => [
                    'id' => $tool->getId(),
                    'name' => $tool->getName(),
                    'status' => $tool->getStatus(),
                ],
            ];
            if ($userAnswer !== '') {
                $responseData['user_answer'] = $userAnswer;
            }
            if ($secretName !== '' && $secretValue !== '') {
                $responseData['secret_stored'] = true;
                $responseData['secret_name'] = $secretName;
            }

            return $this->json($responseData);
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
