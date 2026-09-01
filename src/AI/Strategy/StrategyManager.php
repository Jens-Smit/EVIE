<?php

namespace App\AI\Strategy;

use App\AI\Agent\OrchestratorDialogService;
use App\AI\Decision\DecisionManager;
use App\Entity\AgentGoal;
use App\Entity\GoalEvaluation;
use App\Entity\UserProfile;
use App\Repository\AgentGoalRepository;
use App\Repository\GoalEvaluationRepository;
use App\Repository\UserProfileRepository;
use Psr\Log\LoggerInterface;

/**
 * Manager für Strategie-Anpassungen basierend auf Evaluationen.
 * 
 * Entwickelt und schlägt Anpassungen der Strategie vor, die vom Nutzer
 * per HITL genehmigt werden müssen.
 */
class StrategyManager
{
    public function __construct(
        private AgentGoalRepository $goalRepo,
        private GoalEvaluationRepository $evaluationRepo,
        private UserProfileRepository $userProfileRepo,
        private DecisionManager $decisionManager,
        private OrchestratorDialogService $orchestratorDialogService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Analysiere alle Ziele eines Tenants und schlage Strategie-Anpassungen vor
     * 
     * @param string $userIdentifier Der Tenant-Identifier
     * @param int $lookbackDays Anzahl der Tage zur Analyse
     * @return array Vorschläge für Strategie-Anpassungen
     */
    public function analyzeAndSuggestAdjustments(string $userIdentifier, int $lookbackDays = 30): array
    {
        $this->logger->info('Analysiere Strategie für Tenant', [
            'user_identifier' => $userIdentifier,
            'lookback_days' => $lookbackDays,
        ]);

        // Hole alle Ziele des Tenants
        $goals = $this->goalRepo->findByUser($userIdentifier);

        if (empty($goals)) {
            return [
                'suggestions' => [],
                'summary' => 'Keine Ziele für die Analyse vorhanden.',
            ];
        }

        // Analysiere die Ziele
        $analysis = $this->analyzeGoals($goals, $userIdentifier, $lookbackDays);

        // Generiere Vorschläge
        $suggestions = $this->generateAdjustmentSuggestions($analysis, $goals);

        return [
            'suggestions' => $suggestions,
            'summary' => $this->generateSummary($analysis, $suggestions),
        ];
    }

    /**
     * Analysiere die Ziele
     */
    private function analyzeGoals(array $goals, string $userIdentifier, int $lookbackDays): array
    {
        $analysis = [
            'total_goals' => count($goals),
            'active_goals' => 0,
            'successful_goals' => [],
            'failing_goals' => [],
            'average_score' => null,
            'success_rate' => 0.0,
            'total_executions' => 0,
            'successful_executions' => 0,
        ];

        $date = new \DateTimeImmutable(sprintf('-%d days', $lookbackDays));

        foreach ($goals as $goal) {
            if ($goal->getStatus() === 'active') {
                $analysis['active_goals']++;
            }

            // Hole Evaluationen für dieses Ziel
            $evaluations = $this->evaluationRepo->createQueryBuilder('e')
                ->where('e.goalId = :goalId')
                ->andWhere('e.createdAt >= :date')
                ->setParameter('goalId', $goal->getId())
                ->setParameter('date', $date)
                ->orderBy('e.createdAt', 'DESC')
                ->getQuery()
                ->getResult();

            $analysis['total_executions'] += count($evaluations);

            foreach ($evaluations as $evaluation) {
                if ($evaluation->isSuccess()) {
                    $analysis['successful_executions']++;
                    $analysis['successful_goals'][$goal->getId()] = true;
                } else {
                    $analysis['failing_goals'][$goal->getId()] = true;
                }
            }
        }

        // Berechne Durchschnittswerte
        if ($analysis['total_executions'] > 0) {
            $analysis['success_rate'] = ($analysis['successful_executions'] / $analysis['total_executions']) * 100;
        }

        // Berechne durchschnittlichen Score
        $allScores = [];
        foreach ($goals as $goal) {
            $score = $this->evaluationRepo->getAverageScoreForGoal($goal->getId());
            if (null !== $score) {
                $allScores[] = $score;
            }
        }

        if (!empty($allScores)) {
            $analysis['average_score'] = array_sum($allScores) / count($allScores);
        }

        return $analysis;
    }

    /**
     * Generiere Anpassungsvorschläge
     */
    private function generateAdjustmentSuggestions(array $analysis, array $goals): array
    {
        $suggestions = [];

        // Vorschlag 1: Ziele mit niedriger Erfolgsquote pausieren
        foreach ($goals as $goal) {
            $successRate = $this->evaluationRepo->getSuccessRateForGoal($goal->getId());
            
            if ($successRate < 50 && $goal->getStatus() === 'active') {
                $suggestions[] = [
                    'type' => 'pause_goal',
                    'priority' => 'high',
                    'title' => sprintf('Ziel "%s" pausieren', $goal->getTitle()),
                    'description' => sprintf(
                        'Ziel "%s" hat eine Erfolgsquote von nur %.1f%%. Es sollte pausiert und überprüft werden.',
                        $goal->getTitle(),
                        $successRate
                    ),
                    'action' => sprintf('/agent/goals/%d/pause', $goal->getId()),
                    'goal_id' => $goal->getId(),
                ];
            }
        }

        // Vorschlag 2: Ziele mit hoher Erfolgsquote häufiger ausführen
        foreach ($goals as $goal) {
            $successRate = $this->evaluationRepo->getSuccessRateForGoal($goal->getId());
            
            if ($successRate >= 90 && $goal->getStatus() === 'active') {
                $suggestions[] = [
                    'type' => 'increase_frequency',
                    'priority' => 'medium',
                    'title' => sprintf('Frequenz von "%s" erhöhen', $goal->getTitle()),
                    'description' => sprintf(
                        'Ziel "%s" hat eine Erfolgsquote von %.1f%%. Die Ausführungsfrequenz könnte erhöht werden.',
                        $goal->getTitle(),
                        $successRate
                    ),
                    'action' => sprintf('/agent/goals/%d/edit', $goal->getId()),
                    'goal_id' => $goal->getId(),
                ];
            }
        }

        // Vorschlag 3: Neue Ziele basierend auf Erfolgsmustern vorschlagen
        if ($analysis['success_rate'] >= 75 && $analysis['active_goals'] > 0) {
            $suggestions[] = [
                'type' => 'new_goal_suggestion',
                'priority' => 'medium',
                'title' => 'Neues Ziel vorschlagen',
                'description' => sprintf(
                    'Ihre aktiven Ziele haben eine durchschnittliche Erfolgsquote von %.1f%%. '
                    . 'Sie könnten von zusätzlichen Zielen profitieren.',
                    $analysis['success_rate']
                ),
                'action' => '/agent/goals',
                'goal_id' => null,
            ];
        }

        // Vorschlag 4: Strategie-Review durchführen
        if ($analysis['total_executions'] >= 10) {
            $suggestions[] = [
                'type' => 'strategy_review',
                'priority' => 'medium',
                'title' => 'Strategie-Review durchführen',
                'description' => sprintf(
                    'Es wurden %d Ausführungen in den letzten 30 Tagen durchgeführt. '
                    . 'Ein Strategie-Review könnte die Effektivität weiter steigern.',
                    $analysis['total_executions']
                ),
                'action' => '/strategy',
                'goal_id' => null,
            ];
        }

        // Sortiere nach Priorität
        usort($suggestions, function($a, $b) {
            $priorityOrder = ['high' => 0, 'medium' => 1, 'low' => 2];
            return $priorityOrder[$a['priority']] <=> $priorityOrder[$b['priority']];
        });

        return $suggestions;
    }

    /**
     * Generiere eine Zusammenfassung
     */
    private function generateSummary(array $analysis, array $suggestions): string
    {
        $summary = sprintf(
            "Analyse von %d Zielen mit %d Ausführungen in den letzten 30 Tagen.\n",
            $analysis['total_goals'],
            $analysis['total_executions']
        );

        $summary .= sprintf(
            "Durchschnittliche Erfolgsquote: %.1f%%.\n",
            $analysis['success_rate']
        );

        if (!empty($suggestions)) {
            $summary .= sprintf(
                "%d Anpassungsvorschläge generiert.\n",
                count($suggestions)
            );
        }

        return $summary;
    }

    /**
     * Erstelle eine HITL-Entscheidung für eine Strategie-Anpassung
     */
    public function createStrategyAdjustmentDecision(
        string $userIdentifier,
        string $suggestionType,
        string $title,
        string $description,
        array $context
    ): array {
        return $this->decisionManager->createDecision(
            $userIdentifier,
            'strategy_adjustment',
            $title,
            $description,
            array_merge($context, [
                'suggestion_type' => $suggestionType,
                'created_by' => 'strategy_manager',
            ])
        );
    }

    /**
     * Generiere eine LLM-gestützte Strategie-Empfehlung
     */
    public function generateLLMStrategyRecommendation(string $userIdentifier, array $analysis): string
    {
        $prompt = $this->buildStrategyPrompt($analysis);
        
        return $this->orchestratorDialogService->ask($prompt, $userIdentifier);
    }

    /**
     * Baue den Strategy-Prompt
     */
    private function buildStrategyPrompt(array $analysis): string
    {
        $prompt = "Du bist ein Strategie-Berater für ein AI-Agenten-System.\n\n";
        $prompt .= "Analysiere die folgende Situation und gib Empfehlungen:\n\n";
        $prompt .= sprintf("Anzahl Ziele: %d\n", $analysis['total_goals']);
        $prompt .= sprintf("Aktive Ziele: %d\n", $analysis['active_goals']);
        $prompt .= sprintf("Gesamtausführungen: %d\n", $analysis['total_executions']);
        $prompt .= sprintf("Erfolgsquote: %.1f%%\n", $analysis['success_rate']);
        $prompt .= sprintf("Durchschnittlicher Score: %.2f\n\n", $analysis['average_score'] ?? 0);
        $prompt .= "Gib konkrete Empfehlungen für die Verbesserung der Strategie.\n";

        return $prompt;
    }

    /**
     * Hole alle Anpassungsvorschläge für einen Tenant
     */
    public function getAllSuggestionsForUser(string $userIdentifier): array
    {
        $analysis = $this->analyzeAndSuggestAdjustments($userIdentifier);
        return $analysis['suggestions'];
    }

    /**
     * Wende eine Strategie-Anpassung an
     */
    public function applyAdjustment(int $goalId, string $adjustmentType, string $userIdentifier): bool
    {
        $goal = $this->goalRepo->find($goalId);
        
        if (null === $goal) {
            return false;
        }

        // Prüfe Tenant-Isolation
        if ($goal->getUserIdentifier() !== $userIdentifier) {
            return false;
        }

        switch ($adjustmentType) {
            case 'pause_goal':
                $goal->setStatus('paused');
                break;
            case 'increase_frequency':
                // Erhöhe die Frequenz (z.B. von täglich auf stündlich)
                // Dies ist eine vereinfachte Implementierung
                $currentCron = $goal->getCronExpression();
                if ($currentCron) {
                    // Beispiel: 0 8 * * * (täglich 8 Uhr) -> 0 * * * * (stündlich)
                    $goal->setCronExpression('0 * * * *');
                }
                break;
            default:
                return false;
        }

        $goal->setUpdatedAt(new \DateTimeImmutable());
        $this->goalRepo->save($goal, true);

        return true;
    }
}
