<?php
// src/Controller/ToolApprovalController.php

namespace App\Controller;

use App\Entity\ToolDefinition;
use App\Repository\ToolDefinitionRepository;
use App\AI\Skills\DynamicSkillRegistry;
use App\Event\PendingToolApprovalEvent;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Controller für die Freigabe und Ablehnung von Tools.
 * Unterstützt sowohl HTML- als auch AJAX-Anfragen.
 */
final class ToolApprovalController extends AbstractController
{
    public function __construct(
        private ToolDefinitionRepository $toolDefinitionRepo,
        private DynamicSkillRegistry $dynamicSkillRegistry,
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

        if ($request->isXmlHttpRequest() || $request->headers->get('Accept') === 'application/json') {
            return $this->json([
                'success' => true,
                'tools' => array_map(function (ToolDefinition $tool) {
                    return [
                        'id' => $tool->getId(),
                        'name' => $tool->getName(),
                        'description' => $tool->getDescription(),
                        'created_at' => $tool->getCreatedAt()?->format('Y-m-d H:i:s'),
                        'schema' => $tool->getSchema(),
                    ];
                }, $pendingTools),
            ]);
        }

        // Für HTML-Anfragen: Render Template
        return $this->render('agent/pending_tools.html.twig', [
            'pendingTools' => $pendingTools,
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
            $toolDefinition->setApprovedAt(new \DateTimeImmutable());
            
            // Füge das Tool zum DynamicSkillRegistry hinzu
            $this->dynamicSkillRegistry->addTool($toolDefinition);
            
            $this->logger->info('Tool freigegeben', [
                'tool_id' => $toolDefinition->getId(),
                'tool_name' => $toolDefinition->getName(),
            ]);

            $message = 'Tool wurde erfolgreich freigegeben!';
        } else {
            $toolDefinition->setStatus('rejected');
            $toolDefinition->setRejectedAt(new \DateTimeImmutable());
            
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
        return $this->render('agent/tool_detail.html.twig', [
            'tool' => $toolDefinition,
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
            'approved_at' => $toolDefinition->getApprovedAt()?->format('c'),
            'rejected_at' => $toolDefinition->getRejectedAt()?->format('c'),
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
}