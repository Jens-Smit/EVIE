<?php

// src/Controller/ToolApprovalController.php

namespace App\Controller;

use App\Entity\ToolDefinition;
use App\Event\PendingToolApprovalEvent;
use App\Repository\ToolDefinitionRepository;
use App\Service\SecretService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Controller fuer die Freigabe und Ablehnung von Tools (Faehigkeiten).
 *
 * Implementiert den Human-in-the-Loop (HITL) Mechanismus fuer die
 * Tool-Genehmigung (Blueprint: pending -> Freigabe -> approved ->
 * DynamicToolbox). Die HTML-Liste, die Detailansicht und die
 * Approve/Reject-Endpunkte laufen hier zusammen; die parallele,
 * route-verdeckende GET-Route aus Frontend\ToolApprovalController wurde
 * entfernt, damit /tools/pending genau einen Handler hat.
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
     * Liste aller ausstehenden Tool-Freigaben (zentrale Freigabe-Inbox).
     */
    #[Route('/tools/pending', name: 'app_tool_pending_list', methods: ['GET'])]
    public function listPending(Request $request): Response
    {
        $pendingTools = $this->findPendingTools();

        if ($request->isXmlHttpRequest() || $request->headers->get('Accept') === 'application/json') {
            return $this->json([
                'status' => 'success',
                'count' => count($pendingTools),
                'tools' => array_map(static fn (ToolDefinition $tool): array => self::serializeTool($tool), $pendingTools),
            ]);
        }

        return $this->render('tools/pending.html.twig', [
            'tools' => array_map(static fn (ToolDefinition $tool): array => self::serializeTool($tool), $pendingTools),
        ]);
    }

    /**
     * Zeigt die Details eines ausstehenden Tools (Was? Warum? Risiko?).
     */
    #[Route('/tools/pending/{id}', name: 'app_tool_pending_show', methods: ['GET'], priority: 2)]
    public function showPending(ToolDefinition $tool): Response
    {
        if (!in_array($tool->getStatus(), ['pending', 'pending_approval'], true)) {
            throw $this->createNotFoundException('Tool nicht gefunden oder bereits bearbeitet');
        }

        return $this->render('tools/pending_show.html.twig', [
            'tool' => self::serializeTool($tool),
        ]);
    }

    /**
     * Genehmigt ein ausstehendes Tool.
     */
    #[Route('/tools/pending/{id}/approve', name: 'app_tool_pending_approve', methods: ['POST'], priority: 3)]
    public function approveTool(Request $request, ToolDefinition $tool): JsonResponse
    {
        if (!$this->isCsrfTokenValid('tool_approval', (string) $request->request->get('_token', $request->headers->get('X-CSRF-TOKEN', '')))) {
            return $this->json([
                'status' => 'error',
                'message' => 'Ungültiges CSRF-Token.',
            ], Response::HTTP_FORBIDDEN);
        }

        if (!in_array($tool->getStatus(), ['pending', 'pending_approval'], true)) {
            return $this->json([
                'status' => 'error',
                'message' => 'Diese Fähigkeit wurde bereits bearbeitet.',
            ], Response::HTTP_CONFLICT);
        }

        $isAjax = $request->isXmlHttpRequest();

        try {
            // Freitext-Antwort des Users: die Antwort wird im metadata-Feld
            // der ToolDefinition gespeichert und ist fuer die Wiederaufnahme
            // des pausierten Plans verfuegbar.
            $userAnswer = trim((string) $request->request->get('user_answer', $request->request->get('answer', '')));

            // Secret hinterlegen: der User kann waehrend der Freigabe einen
            // API-Key/ein Secret mit Bezeichnung hinterlegen.
            $secretName = trim((string) $request->request->get('secret_name', ''));
            $secretValue = trim((string) $request->request->get('secret_value', ''));
            $secretScope = trim((string) $request->request->get('secret_scope', ''));

            $tool->setStatus('approved');
            $tool->setApprovedAt(new \DateTimeImmutable());

            $metadata = $tool->getMetadata() ?? [];
            if ($userAnswer !== '') {
                $metadata['user_answer'] = $userAnswer;
            }
            if ($secretName !== '' && $secretValue !== '') {
                $metadata['secret_name'] = $secretName;
            }
            $tool->setMetadata($metadata);
            $this->toolDefinitionRepo->save($tool, true);

            $userIdentifier = $tool->getUserIdentifier();
            if ($secretName !== '' && $secretValue !== '' && null !== $userIdentifier && $userIdentifier !== '') {
                $this->secretService->set(
                    $secretName,
                    $secretValue,
                    $userIdentifier,
                    $secretScope !== '' ? $secretScope : null,
                );
            }

            $this->dispatcher->dispatch(new PendingToolApprovalEvent(
                $tool,
                $userIdentifier,
                true,
            ));

            $responseData = [
                'status' => 'success',
                'message' => 'Fähigkeit wurde freigegeben. EVIE kann sie jetzt verwenden.',
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

            if (!$isAjax) {
                // Klassischer Formular-POST ohne JavaScript: zurueck zur
                // Freigabe-Inbox mit Bestaetigung statt roher JSON-Antwort.
                $this->addFlash('success', 'Fähigkeit „' . $tool->getName() . '“ wurde freigegeben.');

                return $this->redirectToRoute('app_tool_pending_list');
            }

            return $this->json($responseData);
        } catch (\Exception $e) {
            $this->logger->error('Fehler beim Genehmigen des Tools: ' . $e->getMessage());

            return $this->json([
                'status' => 'error',
                'message' => 'Fehler beim Genehmigen des Tools',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Lehnt ein ausstehendes Tool ab.
     */
    #[Route('/tools/pending/{id}/reject', name: 'app_tool_pending_reject', methods: ['POST'], priority: 3)]
    public function rejectTool(Request $request, ToolDefinition $tool): JsonResponse
    {
        if (!$this->isCsrfTokenValid('tool_approval', (string) $request->request->get('_token', $request->headers->get('X-CSRF-TOKEN', '')))) {
            return $this->json([
                'status' => 'error',
                'message' => 'Ungültiges CSRF-Token.',
            ], Response::HTTP_FORBIDDEN);
        }

        if (!in_array($tool->getStatus(), ['pending', 'pending_approval'], true)) {
            return $this->json([
                'status' => 'error',
                'message' => 'Diese Fähigkeit wurde bereits bearbeitet.',
            ], Response::HTTP_CONFLICT);
        }

        $isAjax = $request->isXmlHttpRequest();

        try {
            $reason = trim((string) $request->request->get('reason', ''));
            $tool->setStatus('rejected');
            $tool->setRejectedAt(new \DateTimeImmutable());
            $tool->setRejectionReason($reason !== '' ? $reason : null);
            $this->toolDefinitionRepo->save($tool, true);

            $this->dispatcher->dispatch(new PendingToolApprovalEvent($tool, $tool->getUserIdentifier(), false));

            if (!$isAjax) {
                $this->addFlash('warning', 'Fähigkeit „' . $tool->getName() . '“ wurde abgelehnt.');

                return $this->redirectToRoute('app_tool_pending_list');
            }

            return $this->json([
                'status' => 'success',
                'message' => 'Fähigkeit wurde abgelehnt. EVIE sucht nach einer Alternative.',
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
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Setzt den Status eines Tools zurueck auf pending.
     */
    #[Route('/tools/pending/{id}/reset', name: 'app_tool_pending_reset', methods: ['POST'], priority: 3)]
    public function resetToolStatus(Request $request, ToolDefinition $tool): JsonResponse
    {
        if (!$this->isCsrfTokenValid('tool_approval', (string) $request->request->get('_token', $request->headers->get('X-CSRF-TOKEN', '')))) {
            return $this->json([
                'status' => 'error',
                'message' => 'Ungültiges CSRF-Token.',
            ], Response::HTTP_FORBIDDEN);
        }

        try {
            $tool->setStatus('pending');
            $tool->setApprovedAt(null);
            $tool->setRejectedAt(null);
            $tool->setRejectionReason(null);
            $this->toolDefinitionRepo->save($tool, true);

            return $this->json([
                'status' => 'success',
                'message' => 'Fähigkeits-Status zurückgesetzt',
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
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Gibt die Anzahl der ausstehenden Tools zurueck (fuer Sidebar Badge).
     */
    #[Route('/api/pending-tools/count', name: 'app_pending_tools_count', methods: ['GET'])]
    public function getPendingToolsCount(): JsonResponse
    {
        return $this->json([
            'count' => count($this->findPendingTools()),
        ]);
    }

    /**
     * @return ToolDefinition[]
     */
    private function findPendingTools(): array
    {
        $pendingTools = $this->toolDefinitionRepo->findBy([
            'status' => ['pending', 'pending_approval'],
        ]);

        usort($pendingTools, static fn (ToolDefinition $a, ToolDefinition $b): int => $b->getCreatedAt() <=> $a->getCreatedAt());

        return $pendingTools;
    }

    /**
     * Serialisiert eine ToolDefinition in das vom Template erwartete Format.
     *
     * @return array<string, mixed>
     */
    private static function serializeTool(ToolDefinition $tool): array
    {
        return [
            'id' => $tool->getId(),
            'name' => $tool->getName(),
            'description' => $tool->getDescription(),
            'requester' => 'EVIE',
            'timestamp' => $tool->getCreatedAt(),
            'schema' => $tool->getSchema(),
            'status' => $tool->getStatus(),
            'security_level' => $tool->getSecurityLevel(),
            'requires_hitl' => $tool->getRequiresHitl(),
            'rejection_reason' => $tool->getRejectionReason(),
            'approved_at' => $tool->getApprovedAt(),
            'rejected_at' => $tool->getRejectedAt(),
        ];
    }
}
