<?php

// src/AI/Briefing/BriefingManager.php

namespace App\AI\Briefing;

use App\AI\Decision\DecisionManager;
use App\AI\Workflow\HitlWorkflowManager;
use App\Entity\AgentHistory;
use App\Repository\AgentHistoryRepository;
use Psr\Log\LoggerInterface;

/**
 * Erstellt regelmäßige Briefings für den User.
 * Faßt den aktuellen Stand aller Aktivitäten zusammen und gibt Empfehlungen.
 *
 * P3-D Konsolidierung: Die Abhängigkeit von WorkflowOrchestrator wurde
 * aufgelöst. getActiveWorkflows() nutzt jetzt HitlWorkflowManager direkt
 * (die einzige Funktionalität, die BriefingManager von WorkflowOrchestrator
 * bezog). WorkflowOrchestrator wurde als nicht mehr verdrahtete Schicht
 * entfernt.
 */
class BriefingManager
{
    public function __construct(
        private AgentHistoryRepository $historyRepo,
        private DecisionManager $decisionManager,
        private HitlWorkflowManager $hitlWorkflowManager,
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
            ->join('h.user', 'u')
            ->where('u.userIdentifier = :user')
            ->andWhere('h.createdAt >= :date')
            ->setParameter('user', $userIdentifier)
            ->setParameter('date', $oneDayAgo)
            ->orderBy('h.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return array_map(function($task) {
            $details = json_decode($task->getDetails() ?? '{}', true) ?? [];

            return [
                'id' => $task->getId(),
                'description' => $details['input']['message'] ?? $details['input']['task'] ?? 'Unbekannte Aufgabe',
                'agent' => $details['agent'] ?? null,
                'executed_at' => $task->getCreatedAt()->format('Y-m-d H:i:s'),
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
     * Gibt aktive Workflows zurück.
     *
     * P3-D: Logik direkt aus WorkflowOrchestrator::getActiveWorkflows()
     * übernommen. Nutzt HitlWorkflowManager direkt, ohne die
     * WorkflowOrchestrator-Zwischenschicht.
     */
    private function getActiveWorkflows(string $userIdentifier): array
    {
        $pending = $this->hitlWorkflowManager->getPendingExecutions();

        $workflows = array_map(function ($pe) use ($userIdentifier) {
            $data = $pe->toArray();
            // Nur Workflows des angefragten Benutzers berücksichtigen.
            if ($userIdentifier !== null && ($data['user_email'] ?? null) !== $userIdentifier) {
                return null;
            }

            return [
                'id' => $data['execution_id'] ?? '',
                'task' => $data['original_request'] ?? $data['tool_name'] ?? '',
                'status' => 'pending',
                'progress' => 0,
                'estimated_duration' => 'unbekannt',
                'risk_level' => 'medium',
                'created_at' => $data['created_at'] ?? '',
            ];
        }, $pending);

        return array_values(array_filter($workflows, fn ($w) => $w !== null));
    }

    /**
     * Gibt Tool-Statistiken zurück
     */
    private function getToolStatistics(string $userIdentifier): array
    {
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

        $executedAt = $createdAt;

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
