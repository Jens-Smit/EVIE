<?php
// src/AI/Briefing/BriefingManager.php

namespace App\AI\Briefing;

use App\AI\Decision\DecisionManager;
use App\AI\Workflow\WorkflowOrchestrator;
use App\Repository\AgentHistoryRepository;
use Psr\Log\LoggerInterface;

/**
 * Erstellt regelmäßige Briefings für den User.
 * Fässt den aktuellen Stand aller Aktivitäten zusammen und gibt Empfehlungen.
 */
class BriefingManager
{
    public function __construct(
        private AgentHistoryRepository $historyRepo,
        private DecisionManager $decisionManager,
        private WorkflowOrchestrator $workflowOrchestrator,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Erstellt ein tägliches Briefing
     */
    public function createDailyBriefing(string $userIdentifier): array
    {
        $this->logger->info('Erstelle tägliches Briefing', ['user' => $userIdentifier]);

        $briefing = [
            'date' => new \DateTimeImmutable(),
            'user' => $userIdentifier,
            'sections' => [
                'completed_tasks' => $this->getCompletedTasks($userIdentifier),
                'pending_decisions' => $this->getPendingDecisions($userIdentifier),
                'active_workflows' => $this->getActiveWorkflows($userIdentifier),
                'tool_statistics' => $this->getToolStatistics($userIdentifier),
                'recommendations' => $this->generateRecommendations($userIdentifier),
            ],
        ];

        $this->logger->info('Tägliches Briefing erstellt', [
            'completed_tasks' => count($briefing['sections']['completed_tasks']),
            'pending_decisions' => count($briefing['sections']['pending_decisions']),
            'active_workflows' => count($briefing['sections']['active_workflows']),
        ]);

        return $briefing;
    }

    /**
     * Erstellt ein wöchentliches Strategie-Briefing
     */
    public function createWeeklyStrategyBriefing(string $userIdentifier): array
    {
        $this->logger->info('Erstelle wöchentliches Strategie-Briefing', ['user' => $userIdentifier]);

        $briefing = $this->createDailyBriefing($userIdentifier);

        // Ergänze um strategische Analysen
        $briefing['sections']['strategic_analysis'] = $this->generateStrategicAnalysis($userIdentifier);
        $briefing['sections']['upcoming_tasks'] = $this->getUpcomingTasks($userIdentifier);
        $briefing['sections']['resource_allocation'] = $this->analyzeResourceAllocation($userIdentifier);

        return $briefing;
    }

    /**
     * Gibt abgeschlossene Aufgaben zurück
     */
    private function getCompletedTasks(string $userIdentifier): array
    {
        $oneDayAgo = new \DateTimeImmutable('-1 day');

        $completedTasks = $this->historyRepo->createQueryBuilder('h')
            ->where('h.userProfile = :user')
            ->andWhere('h.status = :status')
            ->andWhere('h.executedAt >= :date')
            ->setParameter('user', $userIdentifier)
            ->setParameter('status', 'success')
            ->setParameter('date', $oneDayAgo)
            ->orderBy('h.executedAt', 'DESC')
            ->getQuery()
            ->getResult();

        return array_map(function($task) {
            return [
                'id' => $task->getId(),
                'description' => $task->getInput()['message'] ?? $task->getInput()['task'] ?? 'Unbekannte Aufgabe',
                'agent' => $task->getAgentName(),
                'executed_at' => $task->getExecutedAt()->format('Y-m-d H:i:s'),
                'duration' => $this->calculateDuration($task),
            ];
        }, $completedTasks);
    }

    /**
     * Gibt ausstehende Entscheidungen zurück
     */
    private function getPendingDecisions(string $userIdentifier): array
    {
        $pendingDecisions = $this->decisionManager->getPendingDecisions($userIdentifier);

        return array_map(function($decision) {
            return [
                'id' => $decision['id'],
                'type' => $decision['type'],
                'description' => $decision['description'],
                'created_at' => $decision['created_at'],
                'context' => $decision['context'],
            ];
        }, $pendingDecisions);
    }

    /**
     * Gibt aktive Workflows zurück
     */
    private function getActiveWorkflows(string $userIdentifier): array
    {
        $activeWorkflows = $this->workflowOrchestrator->getActiveWorkflows($userIdentifier);

        return array_map(function($workflow) {
            return [
                'id' => $workflow['id'],
                'task' => $workflow['task'],
                'status' => $workflow['status'],
                'progress' => $workflow['progress'],
                'estimated_duration' => $workflow['estimated_duration'],
                'risk_level' => $workflow['risk_level'],
                'created_at' => $workflow['created_at'],
            ];
        }, $activeWorkflows);
    }

    /**
     * Gibt Tool-Statistiken zurück
     */
    private function getToolStatistics(string $userIdentifier): array
    {
        // Hier könnten wir echte Statistiken aus der Tool-Nutzung holen
        // Für jetzt: Platzhalter
        return [
            'total_tools' => 15,
            'approved_tools' => 12,
            'pending_tools' => 3,
            'rejected_tools' => 0,
        ];
    }

    /**
     * Generiert Empfehlungen
     */
    private function generateRecommendations(string $userIdentifier): array
    {
        $recommendations = [];

        // Empfehlung: Tools genehmigen
        $pendingTools = $this->decisionManager->countPendingDecisions($userIdentifier);
        if ($pendingTools > 0) {
            $recommendations[] = [
                'type' => 'tool_approval',
                'priority' => 'high',
                'description' => sprintf('Es warten %d Tools auf Freigabe', $pendingTools),
                'action' => '/frontend/tools/pending',
            ];
        }

        // Empfehlung: Workflows starten
        $recommendations[] = [
            'type' => 'workflow',
            'priority' => 'medium',
            'description' => 'Starte einen neuen Workflow für komplexe Aufgaben',
            'action' => '/frontend/agent/dialog',
        ];

        // Empfehlung: Briefing prüfen
        $recommendations[] = [
            'type' => 'briefing',
            'priority' => 'low',
            'description' => 'Prüfe das wöchentliche Strategie-Briefing',
            'action' => '/briefing',
        ];

        return $recommendations;
    }

    /**
     * Generiert strategische Analysen
     */
    private function generateStrategicAnalysis(string $userIdentifier): array
    {
        return [
            'trends' => [
                'Most used tools this week',
                'Frequent task patterns',
                'Increasing automation adoption',
            ],
            'opportunities' => [
                'Automation potential for repetitive tasks',
                'Integration with new APIs',
                'Expand tool capabilities',
            ],
            'risks' => [
                'Pending decisions may block workflows',
                'Unused tools could be archived',
                'High-risk operations need review',
            ],
        ];
    }

    /**
     * Gibt anstehende Aufgaben zurück
     */
    private function getUpcomingTasks(string $userIdentifier): array
    {
        // Hier könnten wir geplante Aufgaben aus einem Kalender oder Task-System holen
        // Für jetzt: Platzhalter
        return [
            [
                'description' => 'Wöchentliche Tool-Review durchführen',
                'due_date' => (new \DateTimeImmutable('+3 days'))->format('Y-m-d'),
                'priority' => 'medium',
            ],
            [
                'description' => 'Neue API-Integration testen',
                'due_date' => (new \DateTimeImmutable('+7 days'))->format('Y-m-d'),
                'priority' => 'high',
            ],
        ];
    }

    /**
     * Analysiert die Ressourcenverteilung
     */
    private function analyzeResourceAllocation(string $userIdentifier): array
    {
        // Hier könnten wir die Nutzung der verschiedenen Sub-Agenten analysieren
        // Für jetzt: Platzhalter
        return [
            'sub_agents' => [
                ['name' => 'website_researcher', 'usage' => 45, 'capacity' => 100],
                ['name' => 'data_analyst', 'usage' => 30, 'capacity' => 100],
                ['name' => 'code_assistant', 'usage' => 20, 'capacity' => 100],
                ['name' => 'communication_manager', 'usage' => 5, 'capacity' => 100],
            ],
            'recommendations' => [
                'Increase capacity for website_researcher',
                'Review underutilized agents',
            ],
        ];
    }

    /**
     * Berechnet die Dauer einer Aufgabe
     */
    private function calculateDuration(AgentHistory $task): string
    {
        $createdAt = $task->getCreatedAt();
        $executedAt = $task->getExecutedAt();

        if (!$executedAt) {
            return 'N/A';
        }

        $seconds = $executedAt->getTimestamp() - $createdAt->getTimestamp();

        if ($seconds < 60) {
            return $seconds . 's';
        }
        if ($seconds < 3600) {
            $minutes = floor($seconds / 60);
            $remainingSeconds = $seconds % 60;
            return $minutes . 'm ' . $remainingSeconds . 's';
        }
        $hours = floor($seconds / 3600);
        $remainingMinutes = floor(($seconds % 3600) / 60);
        return $hours . 'h ' . $remainingMinutes . 'm';
    }
}
