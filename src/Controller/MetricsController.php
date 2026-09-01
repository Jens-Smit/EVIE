<?php
// src/Controller/MetricsController.php

namespace App\Controller;

use App\AI\Security\AuditLogger;
use App\Repository\AgentHistoryRepository;
use App\Repository\AuditLogRepository;
use App\Repository\ToolDefinitionRepository;
use App\Repository\StreamingSessionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Controller für Metrics-Export (P0-9 Observability).
 * Exportiert echte Metriken aus der Datenbank für Monitoring.
 */
#[Route('/api/metrics', name: 'api_metrics_')]
class MetricsController extends AbstractController
{
    public function __construct(
        private AuditLogRepository $auditLogRepository,
        private AgentHistoryRepository $agentHistoryRepository,
        private ToolDefinitionRepository $toolDefinitionRepository,
        private StreamingSessionRepository $streamingSessionRepository,
        private AuditLogger $auditLogger
    ) {
    }

    /**
     * Exportiert alle Metriken im JSON-Format.
     * Endpunkt: GET /api/metrics
     */
    #[Route('', name: 'index', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function index(Request $request): JsonResponse
    {
        $metrics = $this->collectAllMetrics();
        
        return $this->json([
            'status' => 'success',
            'data' => $metrics,
            'timestamp' => (new \DateTimeImmutable())->format('c'),
        ]);
    }

    /**
     * Exportiert Token-Usage Metriken.
     */
    #[Route('/token-usage', name: 'token_usage', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function tokenUsageMetrics(): JsonResponse
    {
        $tokenMetrics = [];
        
        // Token-Usage nach Modell aus AgentHistory
        $byModel = $this->agentHistoryRepository->createQueryBuilder('ah')
            ->select('ah.model, SUM(ah.inputTokens) as inputTokens, SUM(ah.outputTokens) as outputTokens')
            ->where('ah.model IS NOT NULL')
            ->groupBy('ah.model')
            ->getQuery()
            ->getResult();
        
        foreach ($byModel as $row) {
            $model = $row['model'] ?? 'unknown';
            $inputTokens = (int) ($row['inputTokens'] ?? 0);
            $outputTokens = (int) ($row['outputTokens'] ?? 0);
            
            $tokenMetrics[] = sprintf(
                'evie_token_usage_total{model="%s",type="input"} %d',
                $model,
                $inputTokens
            );
            
            $tokenMetrics[] = sprintf(
                'evie_token_usage_total{model="%s",type="output"} %d',
                $model,
                $outputTokens
            );
        }
        
        // Gesamt Token Usage
        $totalInput = $this->agentHistoryRepository->createQueryBuilder('ah')
            ->select('SUM(ah.inputTokens) as total')
            ->getQuery()
            ->getSingleScalarResult();
        
        $totalOutput = $this->agentHistoryRepository->createQueryBuilder('ah')
            ->select('SUM(ah.outputTokens) as total')
            ->getQuery()
            ->getSingleScalarResult();
        
        $tokenMetrics[] = sprintf('evie_token_input_total %d', (int) ($totalInput ?? 0));
        $tokenMetrics[] = sprintf('evie_token_output_total %d', (int) ($totalOutput ?? 0));

        return $this->json([
            'status' => 'success',
            'metrics' => $tokenMetrics,
            'type' => 'prometheus',
            'summary' => [
                'total_input_tokens' => (int) ($totalInput ?? 0),
                'total_output_tokens' => (int) ($totalOutput ?? 0),
                'by_model' => array_map(function($row) {
                    return [
                        'model' => $row['model'],
                        'input' => (int) ($row['inputTokens'] ?? 0),
                        'output' => (int) ($row['outputTokens'] ?? 0),
                    ];
                }, $byModel),
            ],
        ]);
    }

    /**
     * Exportiert Latenz-Metriken.
     */
    #[Route('/latency', name: 'latency', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function latencyMetrics(): JsonResponse
    {
        $latencyMetrics = [];
        
        // Latenz nach Action aus AgentHistory
        $byAction = $this->agentHistoryRepository->createQueryBuilder('ah')
            ->select('ah.action, AVG(ah.latencySeconds) as avgLatency, MAX(ah.latencySeconds) as maxLatency, MIN(ah.latencySeconds) as minLatency, COUNT(ah.id) as count')
            ->where('ah.latencySeconds > 0')
            ->groupBy('ah.action')
            ->getQuery()
            ->getResult();
        
        foreach ($byAction as $row) {
            $action = $row['action'] ?? 'unknown';
            $avgLatency = (float) ($row['avgLatency'] ?? 0);
            $count = (int) ($row['count'] ?? 0);
            
            if ($count > 0 && $avgLatency > 0) {
                $latencyMetrics[] = sprintf(
                    'evie_agent_response_latency_seconds{action="%s"} %.4f',
                    $action,
                    $avgLatency
                );
                
                $latencyMetrics[] = sprintf(
                    'evie_agent_response_latency_count{action="%s"} %d',
                    $action,
                    $count
                );
            }
        }
        
        // Gesamt Latenz Statistiken
        $avgLatency = $this->agentHistoryRepository->createQueryBuilder('ah')
            ->select('AVG(ah.latencySeconds) as avg')
            ->where('ah.latencySeconds > 0')
            ->getQuery()
            ->getSingleScalarResult();
        
        $maxLatency = $this->agentHistoryRepository->createQueryBuilder('ah')
            ->select('MAX(ah.latencySeconds) as max')
            ->where('ah.latencySeconds > 0')
            ->getQuery()
            ->getSingleScalarResult();
        
        $latencyMetrics[] = sprintf('evie_agent_response_latency_seconds_avg %.4f', (float) ($avgLatency ?? 0));
        $latencyMetrics[] = sprintf('evie_agent_response_latency_seconds_max %.4f', (float) ($maxLatency ?? 0));

        return $this->json([
            'status' => 'success',
            'metrics' => $latencyMetrics,
            'type' => 'prometheus',
            'summary' => [
                'avg_latency_seconds' => (float) ($avgLatency ?? 0),
                'max_latency_seconds' => (float) ($maxLatency ?? 0),
                'by_action' => array_map(function($row) {
                    return [
                        'action' => $row['action'],
                        'avg_latency' => (float) ($row['avgLatency'] ?? 0),
                        'max_latency' => (float) ($row['maxLatency'] ?? 0),
                        'min_latency' => (float) ($row['minLatency'] ?? 0),
                        'count' => (int) ($row['count'] ?? 0),
                    ];
                }, $byAction),
            ],
        ]);
    }

    /**
     * Exportiert Tool-Erfolgsraten.
     */
    #[Route('/tool-success-rate', name: 'tool_success_rate', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function toolSuccessRateMetrics(): JsonResponse
    {
        $successMetrics = [];
        
        // Tool-Statistiken aus Audit-Logs
        $byTool = $this->auditLogRepository->createQueryBuilder('al')
            ->select('al.entityType, al.entityId, al.status, COUNT(al.id) as count')
            ->where('al.entityType = :entityType')
            ->setParameter('entityType', 'ToolDefinition')
            ->groupBy('al.entityType, al.entityId, al.status')
            ->getQuery()
            ->getResult();
        
        $toolStats = [];
        foreach ($byTool as $row) {
            $toolId = $row['entityId'] ?? 'unknown';
            $status = $row['status'] ?? 'unknown';
            $count = (int) ($row['count'] ?? 0);
            
            if (!isset($toolStats[$toolId])) {
                $toolStats[$toolId] = ['success' => 0, 'failure' => 0, 'total' => 0];
            }
            
            $toolStats[$toolId][$status] += $count;
            $toolStats[$toolId]['total'] += $count;
        }
        
        // Tool-Namen aus ToolDefinitionRepository holen
        $tools = $this->toolDefinitionRepository->findAll();
        $toolNames = [];
        foreach ($tools as $tool) {
            $toolNames[$tool->getId()] = $tool->getName();
        }
        
        foreach ($toolStats as $toolId => $stats) {
            $toolName = $toolNames[$toolId] ?? "tool_$toolId";
            
            $successMetrics[] = sprintf(
                'evie_tool_execution_total{tool="%s",status="success"} %d',
                $toolName,
                $stats['success']
            );
            
            $successMetrics[] = sprintf(
                'evie_tool_execution_total{tool="%s",status="failure"} %d',
                $toolName,
                $stats['failure']
            );
            
            if ($stats['total'] > 0) {
                $successRate = ($stats['success'] / $stats['total']) * 100;
                $successMetrics[] = sprintf(
                    'evie_tool_success_rate{tool="%s"} %.2f',
                    $toolName,
                    $successRate
                );
            }
        }

        return $this->json([
            'status' => 'success',
            'metrics' => $successMetrics,
            'type' => 'prometheus',
            'summary' => [
                'total_tools' => count($tools),
                'by_tool' => array_map(function($toolId, $stats) use ($toolNames) {
                    return [
                        'tool_id' => $toolId,
                        'tool_name' => $toolNames[$toolId] ?? "tool_$toolId",
                        'success' => $stats['success'],
                        'failure' => $stats['failure'],
                        'total' => $stats['total'],
                        'success_rate' => $stats['total'] > 0 ? ($stats['success'] / $stats['total']) * 100 : 0,
                    ];
                }, array_keys($toolStats), $toolStats),
            ],
        ]);
    }

    /**
     * Exportiert Audit-Log Metriken.
     */
    #[Route('/audit', name: 'audit', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function auditMetrics(): JsonResponse
    {
        $total = $this->auditLogRepository->count([]);
        $success = $this->auditLogRepository->count(['status' => 'success']);
        $failure = $this->auditLogRepository->count(['status' => 'failure']);
        
        $metrics = [];
        $metrics[] = sprintf('evie_audit_log_total %d', $total);
        $metrics[] = sprintf('evie_audit_log_total{status="%s"} %d', 'success', $success);
        $metrics[] = sprintf('evie_audit_log_total{status="%s"} %d', 'failure', $failure);
        
        // Nach Aktionen gruppiert
        $actions = $this->auditLogRepository->createQueryBuilder('a')
            ->select('a.action, COUNT(a.id) as count')
            ->groupBy('a.action')
            ->getQuery()
            ->getResult();
        
        foreach ($actions as $action) {
            $metrics[] = sprintf(
                'evie_audit_log_total{action="%s"} %d',
                $action['action'],
                (int) $action['count']
            );
        }

        return $this->json([
            'status' => 'success',
            'metrics' => $metrics,
            'type' => 'prometheus',
            'summary' => [
                'total' => $total,
                'success' => $success,
                'failure' => $failure,
                'by_action' => array_map(function($action) {
                    return [
                        'action' => $action['action'],
                        'count' => (int) $action['count'],
                    ];
                }, $actions),
            ],
        ]);
    }

    /**
     * Sammelt alle Metriken.
     */
    private function collectAllMetrics(): array
    {
        return [
            'token_usage' => $this->getTokenUsageData(),
            'latency' => $this->getLatencyData(),
            'tool_success_rate' => $this->getToolSuccessRateData(),
            'audit' => $this->getAuditData(),
            'streaming' => $this->getStreamingData(),
        ];
    }

    /**
     * Token-Usage Daten.
     */
    private function getTokenUsageData(): array
    {
        $totalInput = $this->agentHistoryRepository->createQueryBuilder('ah')
            ->select('SUM(ah.inputTokens) as total')
            ->getQuery()
            ->getSingleScalarResult();
        
        $totalOutput = $this->agentHistoryRepository->createQueryBuilder('ah')
            ->select('SUM(ah.outputTokens) as total')
            ->getQuery()
            ->getSingleScalarResult();
        
        $byModel = $this->agentHistoryRepository->createQueryBuilder('ah')
            ->select('ah.model, SUM(ah.inputTokens) as inputTokens, SUM(ah.outputTokens) as outputTokens')
            ->where('ah.model IS NOT NULL')
            ->groupBy('ah.model')
            ->getQuery()
            ->getResult();
        
        $modelData = [];
        foreach ($byModel as $row) {
            $modelData[$row['model']] = [
                'input' => (int) ($row['inputTokens'] ?? 0),
                'output' => (int) ($row['outputTokens'] ?? 0),
            ];
        }
        
        return [
            'total_input_tokens' => (int) ($totalInput ?? 0),
            'total_output_tokens' => (int) ($totalOutput ?? 0),
            'by_model' => $modelData,
        ];
    }

    /**
     * Latenz-Daten.
     */
    private function getLatencyData(): array
    {
        $avgLatency = $this->agentHistoryRepository->createQueryBuilder('ah')
            ->select('AVG(ah.latencySeconds) as avg')
            ->where('ah.latencySeconds > 0')
            ->getQuery()
            ->getSingleScalarResult();
        
        $maxLatency = $this->agentHistoryRepository->createQueryBuilder('ah')
            ->select('MAX(ah.latencySeconds) as max')
            ->where('ah.latencySeconds > 0')
            ->getQuery()
            ->getSingleScalarResult();
        
        $p95Latency = $this->calculatePercentile(95);
        $p99Latency = $this->calculatePercentile(99);
        
        return [
            'avg_latency_seconds' => (float) ($avgLatency ?? 0),
            'max_latency_seconds' => (float) ($maxLatency ?? 0),
            'p95_latency_seconds' => $p95Latency,
            'p99_latency_seconds' => $p99Latency,
        ];
    }

    /**
     * Berechnet Percentile für Latenz.
     */
    private function calculatePercentile(float $percentile): float
    {
        $latencies = $this->agentHistoryRepository->createQueryBuilder('ah')
            ->select('ah.latencySeconds')
            ->where('ah.latencySeconds > 0')
            ->orderBy('ah.latencySeconds', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();
        
        if (empty($latencies)) {
            return 0.0;
        }
        
        $count = count($latencies);
        $index = (int) ceil($count * ($percentile / 100)) - 1;
        
        return (float) ($latencies[$index] ?? 0);
    }

    /**
     * Tool-Erfolgsraten Daten.
     */
    private function getToolSuccessRateData(): array
    {
        // Tool-Statistiken aus Audit-Logs
        $byTool = $this->auditLogRepository->createQueryBuilder('al')
            ->select('al.entityType, al.entityId, al.status, COUNT(al.id) as count')
            ->where('al.entityType = :entityType')
            ->setParameter('entityType', 'ToolDefinition')
            ->groupBy('al.entityType, al.entityId, al.status')
            ->getQuery()
            ->getResult();
        
        $toolStats = [];
        foreach ($byTool as $row) {
            $toolId = $row['entityId'] ?? 'unknown';
            $status = $row['status'] ?? 'unknown';
            $count = (int) ($row['count'] ?? 0);
            
            if (!isset($toolStats[$toolId])) {
                $toolStats[$toolId] = ['success' => 0, 'failure' => 0, 'total' => 0];
            }
            
            $toolStats[$toolId][$status] += $count;
            $toolStats[$toolId]['total'] += $count;
        }
        
        $tools = $this->toolDefinitionRepository->findAll();
        $toolNames = [];
        foreach ($tools as $tool) {
            $toolNames[$tool->getId()] = $tool->getName();
        }
        
        $data = [];
        $totalSuccess = 0;
        $totalExecutions = 0;
        
        foreach ($toolStats as $toolId => $stats) {
            $data[$toolNames[$toolId] ?? "tool_$toolId"] = [
                'success_rate' => $stats['total'] > 0 ? ($stats['success'] / $stats['total']) * 100 : 0,
                'total_executions' => $stats['total'],
                'successful_executions' => $stats['success'],
            ];
            $totalSuccess += $stats['success'];
            $totalExecutions += $stats['total'];
        }
        
        return [
            'by_tool' => $data,
            'avg_success_rate' => $totalExecutions > 0 ? ($totalSuccess / $totalExecutions) * 100 : 0,
        ];
    }

    /**
     * Audit-Daten.
     */
    private function getAuditData(): array
    {
        $total = $this->auditLogRepository->count([]);
        $success = $this->auditLogRepository->count(['status' => 'success']);
        $failure = $this->auditLogRepository->count(['status' => 'failure']);
        
        return [
            'total' => $total,
            'success' => $success,
            'failure' => $failure,
        ];
    }

    /**
     * Streaming-Daten.
     */
    private function getStreamingData(): array
    {
        $active = $this->streamingSessionRepository->count(['status' => 'active']);
        $completed = $this->streamingSessionRepository->count(['status' => 'completed']);
        $cancelled = $this->streamingSessionRepository->count(['status' => 'cancelled']);
        
        return [
            'active_sessions' => $active,
            'completed_sessions' => $completed,
            'cancelled_sessions' => $cancelled,
        ];
    }
}
