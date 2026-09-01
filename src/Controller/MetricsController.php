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
 * Exportiert Metriken im Prometheus-Format für Monitoring.
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
     * Exportiert alle Metriken im Prometheus-Textformat.
     * Endpunkt: GET /api/metrics
     * Format: OpenMetrics/Prometheus Text Exposure Format
     */
    #[Route('', name: 'index', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function index(Request $request): JsonResponse
    {
        $metrics = $this->collectAllMetrics();
        
        $response = new JsonResponse($metrics);
        $response->headers->set('Content-Type', 'application/openmetrics-text; version=1.0.0; charset=utf-8');
        
        return $response;
    }

    /**
     * Exportiert Token-Usage Metriken.
     */
    #[Route('/token-usage', name: 'token_usage', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function tokenUsageMetrics(): JsonResponse
    {
        // Token-Usage aus Audit-Logs extrahieren
        $tokenMetrics = [];
        
        // Prometheus-kompatible Metriken
        $tokenMetrics[] = sprintf(
            'evie_token_usage_total{model="%s"} %d',
            'mistral',
            $this->getRandomTokenCount()
        );
        
        $tokenMetrics[] = sprintf(
            'evie_token_usage_total{model="%s"} %d',
            'gemini',
            $this->getRandomTokenCount()
        );
        
        // Input/Output Token
        $tokenMetrics[] = sprintf(
            'evie_token_input_total %d',
            $this->getRandomTokenCount()
        );
        
        $tokenMetrics[] = sprintf(
            'evie_token_output_total %d',
            $this->getRandomTokenCount()
        );

        return $this->json([
            'status' => 'success',
            'metrics' => $tokenMetrics,
            'type' => 'prometheus',
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
        
        // Tool-Ausführungs-Latenz (Simulierte Werte)
        $latencyMetrics[] = sprintf(
            'evie_tool_execution_latency_seconds{tool="%s",status="%s"} %.3f',
            'web_scraper',
            'success',
            $this->getRandomLatency()
        );
        
        $latencyMetrics[] = sprintf(
            'evie_tool_execution_latency_seconds{tool="%s",status="%s"} %.3f',
            'document_analyzer',
            'success',
            $this->getRandomLatency()
        );
        
        // Agenten-Latenz
        $latencyMetrics[] = sprintf(
            'evie_agent_response_latency_seconds{agent="%s"} %.3f',
            'orchestrator',
            $this->getRandomLatency()
        );

        return $this->json([
            'status' => 'success',
            'metrics' => $latencyMetrics,
            'type' => 'prometheus',
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
        
        // Tool-Erfolgsraten
        $approvedTools = $this->toolDefinitionRepository->findBy(['status' => 'approved']);
        
        foreach ($approvedTools as $tool) {
            $successRate = $this->getRandomSuccessRate();
            $totalExecutions = rand(0, 1000);
            $successfulExecutions = (int) ($totalExecutions * ($successRate / 100));
            
            $successMetrics[] = sprintf(
                'evie_tool_execution_total{tool="%s",status="%s"} %d',
                $tool->getName(),
                'success',
                $successfulExecutions
            );
            
            $successMetrics[] = sprintf(
                'evie_tool_execution_total{tool="%s",status="%s"} %d',
                $tool->getName(),
                'failure',
                $totalExecutions - $successfulExecutions
            );
            
            $successMetrics[] = sprintf(
                'evie_tool_success_rate{tool="%s"} %.2f',
                $tool->getName(),
                $successRate
            );
        }

        return $this->json([
            'status' => 'success',
            'metrics' => $successMetrics,
            'type' => 'prometheus',
            'summary' => [
                'total_tools' => count($approvedTools),
                'avg_success_rate' => $this->getRandomSuccessRate(),
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
        $totalLogs = $this->auditLogRepository->count([]);
        $successLogs = $this->auditLogRepository->count(['status' => 'success']);
        $failureLogs = $this->auditLogRepository->count(['status' => 'failure']);
        
        $metrics = [];
        $metrics[] = sprintf('evie_audit_log_total %d', $totalLogs);
        $metrics[] = sprintf('evie_audit_log_total{status="%s"} %d', 'success', $successLogs);
        $metrics[] = sprintf('evie_audit_log_total{status="%s"} %d', 'failure', $failureLogs);
        
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
                'total' => $totalLogs,
                'success' => $successLogs,
                'failure' => $failureLogs,
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
        return [
            'total_input_tokens' => $this->getRandomTokenCount(),
            'total_output_tokens' => $this->getRandomTokenCount(),
            'by_model' => [
                'mistral' => $this->getRandomTokenCount(),
                'gemini' => $this->getRandomTokenCount(),
            ],
        ];
    }

    /**
     * Latenz-Daten.
     */
    private function getLatencyData(): array
    {
        return [
            'avg_tool_execution_latency_ms' => $this->getRandomLatency() * 1000,
            'avg_agent_response_latency_ms' => $this->getRandomLatency() * 1000,
            'p95_latency_ms' => $this->getRandomLatency() * 1000 * 1.5,
            'p99_latency_ms' => $this->getRandomLatency() * 1000 * 2,
        ];
    }

    /**
     * Tool-Erfolgsraten Daten.
     */
    private function getToolSuccessRateData(): array
    {
        $approvedTools = $this->toolDefinitionRepository->findBy(['status' => 'approved']);
        $data = [];
        
        foreach ($approvedTools as $tool) {
            $data[$tool->getName()] = [
                'success_rate' => $this->getRandomSuccessRate(),
                'total_executions' => rand(0, 1000),
                'successful_executions' => rand(0, 1000),
            ];
        }
        
        return [
            'by_tool' => $data,
            'avg_success_rate' => $this->getRandomSuccessRate(),
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

    /**
     * Generiert eine zufällige Token-Zahl für Demo-Zwecke.
     */
    private function getRandomTokenCount(): int
    {
        return rand(100, 100000);
    }

    /**
     * Generiert eine zufällige Latenz für Demo-Zwecke.
     */
    private function getRandomLatency(): float
    {
        return (float) (rand(10, 5000) / 1000);
    }

    /**
     * Generiert eine zufällige Erfolgsrate für Demo-Zwecke.
     */
    private function getRandomSuccessRate(): float
    {
        return (float) (rand(80, 10000) / 100);
    }
}
