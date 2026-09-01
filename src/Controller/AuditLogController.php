<?php
// src/Controller/AuditLogController.php

namespace App\Controller;

use App\Repository\AuditLogRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Controller für Audit-Log-Anzeige und -Verwaltung.
 * Bietet API-Endpunkte und Web-Interface für Audit-Logs (P0-9 Observability).
 */
class AuditLogController extends AbstractController
{
    public function __construct(
        private AuditLogRepository $auditLogRepository
    ) {
    }

    /**
     * Listet alle Audit-Logs auf (für Admins)
     */
    #[Route('/audit-logs', name: 'app_audit_logs', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function listAuditLogs(Request $request): Response
    {
        $page = $request->query->getInt('page', 1);
        $limit = $request->query->getInt('limit', 50);
        $action = $request->query->get('action');
        $status = $request->query->get('status');
        $entityType = $request->query->get('entity_type');

        $queryBuilder = $this->auditLogRepository->createQueryBuilder('a');
        
        if ($action) {
            $queryBuilder->andWhere('a.action = :action')->setParameter('action', $action);
        }
        if ($status) {
            $queryBuilder->andWhere('a.status = :status')->setParameter('status', $status);
        }
        if ($entityType) {
            $queryBuilder->andWhere('a.entityType = :entityType')->setParameter('entityType', $entityType);
        }
        
        $queryBuilder->orderBy('a.createdAt', 'DESC');
        
        $logs = $queryBuilder->getQuery()->getResult();

        return $this->render('audit_logs/index.html.twig', [
            'logs' => $logs,
            'currentPage' => $page,
            'limit' => $limit,
            'total' => count($logs),
            'filters' => [
                'action' => $action,
                'status' => $status,
                'entity_type' => $entityType,
            ],
        ]);
    }

    /**
     * API: Listet Audit-Logs auf
     */
    #[Route('/api/audit-logs', name: 'api_audit_logs', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function apiListAuditLogs(Request $request): JsonResponse
    {
        $page = $request->query->getInt('page', 1);
        $limit = $request->query->getInt('limit', 100);
        $action = $request->query->get('action');
        $status = $request->query->get('status');
        $entityType = $request->query->get('entity_type');
        $userId = $request->query->getInt('user_id');

        $queryBuilder = $this->auditLogRepository->createQueryBuilder('a');
        
        if ($action) {
            $queryBuilder->andWhere('a.action = :action')->setParameter('action', $action);
        }
        if ($status) {
            $queryBuilder->andWhere('a.status = :status')->setParameter('status', $status);
        }
        if ($entityType) {
            $queryBuilder->andWhere('a.entityType = :entityType')->setParameter('entityType', $entityType);
        }
        if ($userId) {
            $queryBuilder->andWhere('a.userId = :userId')->setParameter('userId', $userId);
        }
        
        $queryBuilder->orderBy('a.createdAt', 'DESC');
        $queryBuilder->setMaxResults($limit);
        $queryBuilder->setFirstResult(($page - 1) * $limit);
        
        $logs = $queryBuilder->getQuery()->getResult();
        
        $total = $this->auditLogRepository->count([]);

        $data = [];
        foreach ($logs as $log) {
            $data[] = [
                'id' => $log->getId(),
                'action' => $log->getAction(),
                'entityType' => $log->getEntityType(),
                'entityId' => $log->getEntityId(),
                'userId' => $log->getUserId(),
                'status' => $log->getStatus(),
                'details' => $log->getDetails(),
                'context' => $log->getContext(),
                'ipAddress' => $log->getIpAddress(),
                'userAgent' => $log->getUserAgent(),
                'createdAt' => $log->getCreatedAt()?->format('Y-m-d H:i:s'),
            ];
        }

        return $this->json([
            'status' => 'success',
            'data' => $data,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => ceil($total / $limit),
            ],
        ]);
    }

    /**
     * Zeigt Details eines bestimmten Audit-Logs an
     */
    #[Route('/audit-logs/{id}', name: 'app_audit_log_show', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function showAuditLog(int $id): Response
    {
        $log = $this->auditLogRepository->find($id);
        
        if (!$log) {
            throw $this->createNotFoundException('Audit-Log nicht gefunden');
        }

        return $this->render('audit_logs/show.html.twig', [
            'log' => $log,
        ]);
    }

    /**
     * API: Zeigt Details eines bestimmten Audit-Logs an
     */
    #[Route('/api/audit-logs/{id}', name: 'api_audit_log_show', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function apiShowAuditLog(int $id): JsonResponse
    {
        $log = $this->auditLogRepository->find($id);
        
        if (!$log) {
            return $this->json(['error' => 'Audit-Log nicht gefunden'], 404);
        }

        return $this->json([
            'status' => 'success',
            'data' => [
                'id' => $log->getId(),
                'action' => $log->getAction(),
                'entityType' => $log->getEntityType(),
                'entityId' => $log->getEntityId(),
                'userId' => $log->getUserId(),
                'status' => $log->getStatus(),
                'details' => $log->getDetails(),
                'context' => $log->getContext(),
                'ipAddress' => $log->getIpAddress(),
                'userAgent' => $log->getUserAgent(),
                'createdAt' => $log->getCreatedAt()?->format('Y-m-d H:i:s'),
            ],
        ]);
    }

    /**
     * Filtert Audit-Logs nach Aktion
     */
    #[Route('/api/audit-logs/filter', name: 'api_audit_logs_filter', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function filterAuditLogs(Request $request): JsonResponse
    {
        $action = $request->query->get('action');
        $entityType = $request->query->get('entity_type');
        $status = $request->query->get('status');
        $limit = $request->query->getInt('limit', 100);

        $queryBuilder = $this->auditLogRepository->createQueryBuilder('a');
        
        if ($action) {
            $queryBuilder->andWhere('a.action LIKE :action')->setParameter('action', '%' . $action . '%');
        }
        if ($entityType) {
            $queryBuilder->andWhere('a.entityType = :entityType')->setParameter('entityType', $entityType);
        }
        if ($status) {
            $queryBuilder->andWhere('a.status = :status')->setParameter('status', $status);
        }
        
        $queryBuilder->orderBy('a.createdAt', 'DESC');
        $queryBuilder->setMaxResults($limit);
        
        $logs = $queryBuilder->getQuery()->getResult();
        
        $data = [];
        foreach ($logs as $log) {
            $data[] = [
                'id' => $log->getId(),
                'action' => $log->getAction(),
                'entityType' => $log->getEntityType(),
                'entityId' => $log->getEntityId(),
                'userId' => $log->getUserId(),
                'status' => $log->getStatus(),
                'createdAt' => $log->getCreatedAt()?->format('Y-m-d H:i:s'),
            ];
        }

        return $this->json([
            'status' => 'success',
            'count' => count($data),
            'data' => $data,
        ]);
    }

    /**
     * Gibt Statistiken über Audit-Logs zurück
     */
    #[Route('/api/audit-logs/statistics', name: 'api_audit_logs_statistics', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function auditLogsStatistics(): JsonResponse
    {
        $total = $this->auditLogRepository->count([]);
        
        $successCount = $this->auditLogRepository->count(['status' => 'success']);
        $failureCount = $this->auditLogRepository->count(['status' => 'failure']);
        
        // Actions zählen
        $queryBuilder = $this->auditLogRepository->createQueryBuilder('a');
        $queryBuilder->select('a.action, COUNT(a.id) as count');
        $queryBuilder->groupBy('a.action');
        $actions = $queryBuilder->getQuery()->getResult();
        
        $actionStats = [];
        foreach ($actions as $action) {
            $actionStats[$action['action']] = (int) $action['count'];
        }

        return $this->json([
            'status' => 'success',
            'total' => $total,
            'statistics' => [
                'success' => $successCount,
                'failure' => $failureCount,
                'actions' => $actionStats,
            ],
        ]);
    }

    /**
     * Exportiert Audit-Logs als CSV
     */
    #[Route('/audit-logs/export', name: 'app_audit_logs_export', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function exportAuditLogs(Request $request): Response
    {
        $action = $request->query->get('action');
        $limit = $request->query->getInt('limit', 1000);

        $queryBuilder = $this->auditLogRepository->createQueryBuilder('a');
        
        if ($action) {
            $queryBuilder->andWhere('a.action = :action')->setParameter('action', $action);
        }
        
        $queryBuilder->orderBy('a.createdAt', 'DESC');
        $queryBuilder->setMaxResults($limit);
        
        $logs = $queryBuilder->getQuery()->getResult();

        $csv = "ID,Action,EntityType,EntityID,UserID,Status,Details,IP Address,User Agent,Created At\n";
        
        foreach ($logs as $log) {
            $csv .= sprintf(
                '%d,"%s","%s",%d,%d,%s,"%s","%s","%s","%s"\n',
                $log->getId(),
                $log->getAction() ?? '',
                $log->getEntityType() ?? '',
                $log->getEntityId() ?? 0,
                $log->getUserId() ?? 0,
                $log->getStatus() ?? '',
                str_replace('"', '""', $log->getDetails() ?? ''),
                $log->getIpAddress() ?? '',
                $log->getUserAgent() ?? '',
                $log->getCreatedAt()?->format('Y-m-d H:i:s') ?? ''
            );
        }

        $response = new Response($csv);
        $response->headers->set('Content-Type', 'text/csv');
        $response->headers->set('Content-Disposition', 'attachment; filename="audit_logs_export.csv"');

        return $response;
    }
}
