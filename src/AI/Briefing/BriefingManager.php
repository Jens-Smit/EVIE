<?php

// src/AI/Briefing/BriefingManager.php

namespace App\AI\Briefing;

use App\AI\Decision\DecisionManager;
use App\AI\Workflow\HitlWorkflowManager;
use App\Entity\AgentHistory;
use App\Repository\AgentHistoryRepository;
use App\Repository\SubAgentRepository;
use App\Repository\ToolDefinitionRepository;
use Psr\Log\LoggerInterface;

/**
 * Erstellt regelmäßige Briefings für den User.
 * Faß den aktuellen Stand aller Aktivitäten zusammen und gibt Empfehlungen.
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
        private LoggerInterface $logger,
        private ToolDefinitionRepository $toolDefinitionRepo,
        private SubAgentRepository $subAgentRepo,
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
     * Gibt Tool-Statistiken zurück (datenbasiert aus ToolDefinitionRepository)
     */
    private function getToolStatistics(string $userIdentifier): array
    {
        $total = 0;
        $approved = 0;
        $pending = 0;
        $rejected = 0;

        // Zähle alle Tools für den Tenant
        $tenantTools = $this->toolDefinitionRepo->findBy([
            'userIdentifier' => $userIdentifier
        ]);
        
        foreach ($tenantTools as $tool) {
            $status = $tool->getStatus();
            $total++;
            
            if ($status === 'approved') {
                $approved++;
            } elseif ($status === 'pending') {
                $pending++;
            } elseif ($status === 'rejected') {
                $rejected++;
            }
        }
        
        // Füge System-Tools (userIdentifier = NULL) hinzu
        $systemTools = $this->toolDefinitionRepo->findBy(['userIdentifier' => null]);
        foreach ($systemTools as $tool) {
            $status = $tool->getStatus();
            $total++;
            
            if ($status === 'approved') {
                $approved++;
            } elseif ($status === 'pending') {
                $pending++;
            } elseif ($status === 'rejected') {
                $rejected++;
            }
        }
        
        return [
            'total_tools' => $total,
            'approved_tools' => $approved,
            'pending_tools' => $pending,
            'rejected_tools' => $rejected,
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
     * Generiert strategische Analysen (datenbasiert aus AgentHistory)
     */
    private function generateStrategicAnalysis(string $userIdentifier): array
    {
        $oneWeekAgo = new \DateTimeImmutable('-7 days');
        
        // Hole AgentHistory für den Tenant
        $history = $this->historyRepo->createQueryBuilder('h')
            ->join('h.user', 'u')
            ->where('u.userIdentifier = :user')
            ->andWhere('h.createdAt >= :date')
            ->setParameter('user', $userIdentifier)
            ->setParameter('date', $oneWeekAgo)
            ->orderBy('h.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        if (empty($history)) {
            return [
                'trends' => ['Noch nicht genug Daten für eine Analyse'],
                'opportunities' => ['Nutzen Sie den Agenten, um Daten zu sammeln'],
                'risks' => [],
            ];
        }

        // Analysiere die häufigsten Tools/Aktionen
        $toolUsage = [];
        $taskPatterns = [];
        
        foreach ($history as $entry) {
            $details = json_decode($entry->getDetails() ?? '{}', true) ?? [];
            
            // Tools extrahieren
            if (isset($details['tool_name'])) {
                $toolName = $details['tool_name'];
                $toolUsage[$toolName] = ($toolUsage[$toolName] ?? 0) + 1;
            }
            
            // Aufgabenmuster extrahieren
            if (isset($details['input']['message'])) {
                $message = $details['input']['message'];
                $taskPatterns[$message] = ($taskPatterns[$message] ?? 0) + 1;
            }
        }

        // Sortiere nach Häufigkeit
        arsort($toolUsage);
        arsort($taskPatterns);

        // Baue Trends
        $trends = [];
        if (!empty($toolUsage)) {
            $topTools = array_slice($toolUsage, 0, 3, true);
            foreach ($topTools as $tool => $count) {
                $trends[] = sprintf('Tool %s wurde %d mal verwendet', $tool, $count);
            }
        }
        
        if (!empty($taskPatterns)) {
            $topPatterns = array_slice($taskPatterns, 0, 3, true);
            foreach ($topPatterns as $pattern => $count) {
                $trends[] = sprintf('Aufgabenmuster: %s (%d mal)', substr($pattern, 0, 50), $count);
            }
        }

        if (empty($trends)) {
            $trends[] = 'Noch nicht genug Daten für Trendanalyse';
        }

        // Opportunities
        $opportunities = [];
        if (count($toolUsage) > 5) {
            $opportunities[] = 'Mehrere Tools werden regelmäßig verwendet - Automatisierungspotenzial prüfen';
        }
        if (count($history) > 20) {
            $opportunities[] = 'Hohe Nutzungsfrequenz - Integration mit weiteren Systemen prüfen';
        }
        if (empty($opportunities)) {
            $opportunities[] = 'Nutzen Sie den Agenten häufiger, um mehr Möglichkeiten zu entdecken';
        }

        // Risks
        $risks = [];
        $pendingCount = $this->decisionManager->countPendingDecisions($userIdentifier);
        if ($pendingCount > 0) {
            $risks[] = sprintf('%d ausstehende Entscheidungen könnten Workflows blockieren', $pendingCount);
        }
        
        if (empty($risks)) {
            $risks[] = 'Keine kritischen Risiken erkannt';
        }

        return [
            'trends' => $trends,
            'opportunities' => $opportunities,
            'risks' => $risks,
        ];
    }

    /**
     * Gibt anstehende Aufgaben zurück (datenbasiert aus DecisionLog)
     */
    private function getUpcomingTasks(string $userIdentifier): array
    {
        // Hole offene Entscheidungen mit Fälligkeit
        $pendingDecisions = $this->decisionManager->getPendingDecisions($userIdentifier);
        
        $tasks = [];
        
        foreach ($pendingDecisions as $decision) {
            if (isset($decision['due_date'])) {
                $tasks[] = [
                    'description' => sprintf('Entscheidung treffen: %s', $decision['description']),
                    'due_date' => $decision['due_date'],
                    'priority' => 'high',
                ];
            }
        }

        // Falls keine Aufgaben mit Fälligkeit, leere Liste zurückgeben
        // (keine Fake-Daten mehr)
        if (empty($tasks)) {
            return [];
        }

        // Sortiere nach Fälligkeit
        usort($tasks, function($a, $b) {
            return strtotime($a['due_date']) <=> strtotime($b['due_date']);
        });

        return array_slice($tasks, 0, 5);
    }

    /**
     * Analysiert die Ressourcenverteilung (datenbasiert aus AgentHistory und SubAgent)
     */
    private function analyzeResourceAllocation(string $userIdentifier): array
    {
        // Hole alle Sub-Agenten für den Tenant
        $subAgents = $this->subAgentRepo->createQueryBuilder('sa')
            ->join('sa.user', 'u')
            ->where('u.userIdentifier = :user')
            ->setParameter('user', $userIdentifier)
            ->getQuery()
            ->getResult();

        if (empty($subAgents)) {
            return [
                'sub_agents' => [],
                'recommendations' => ['Noch keine Sub-Agenten für diesen Tenant'],
            ];
        }

        // Hole AgentHistory für Sub-Agent-Nutzung
        $oneMonthAgo = new \DateTimeImmutable('-30 days');
        $history = $this->historyRepo->createQueryBuilder('h')
            ->join('h.user', 'u')
            ->where('u.userIdentifier = :user')
            ->andWhere('h.createdAt >= :date')
            ->setParameter('user', $userIdentifier)
            ->setParameter('date', $oneMonthAgo)
            ->getQuery()
            ->getResult();

        // Zähle Nutzung pro Sub-Agent
        $subAgentUsage = [];
        foreach ($subAgents as $agent) {
            $subAgentUsage[$agent->getName()] = [
                'name' => $agent->getName(),
                'usage' => 0,
                'capacity' => 100,
            ];
        }

        foreach ($history as $entry) {
            $details = json_decode($entry->getDetails() ?? '{}', true) ?? [];
            if (isset($details['sub_agent']) && isset($subAgentUsage[$details['sub_agent']])) {
                $subAgentUsage[$details['sub_agent']]['usage']++;
            }
        }

        // Baue Empfehlungen
        $recommendations = [];
        foreach ($subAgentUsage as $name => $data) {
            $usagePercent = ($data['usage'] / $data['capacity']) * 100;
            
            if ($usagePercent > 80) {
                $recommendations[] = sprintf('Kapazität für %s erhöhen (Auslastung: %d%%)', $name, $usagePercent);
            } elseif ($usagePercent < 10) {
                $recommendations[] = sprintf('%s wird selten verwendet - prüfen ob nötig', $name);
            }
        }

        if (empty($recommendations)) {
            $recommendations[] = 'Ressourcenverteilung ist ausgewogen';
        }

        return [
            'sub_agents' => array_values($subAgentUsage),
            'recommendations' => $recommendations,
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
