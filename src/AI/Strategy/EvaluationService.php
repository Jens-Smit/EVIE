<?php

namespace App\AI\Strategy;

use App\AI\Agent\OrchestratorDialogService;
use App\Entity\AgentGoal;
use App\Entity\AgentHistory;
use App\Entity\GoalEvaluation;
use App\Repository\GoalEvaluationRepository;
use App\Repository\AgentGoalRepository;
use App\Repository\AgentHistoryRepository;
use Psr\Log\LoggerInterface;

/**
 * Service zur Evaluierung von Agent-Ziel-Ergebnissen.
 * 
 * Bewertet Ergebnisse gegen definierte Erfolgsmetriken und speichert Evaluationen.
 */
class EvaluationService
{
    public function __construct(
        private GoalEvaluationRepository $evaluationRepo,
        private AgentGoalRepository $goalRepo,
        private AgentHistoryRepository $historyRepo,
        private OrchestratorDialogService $orchestratorDialogService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Evaluiert ein Agent-Ziel-Ergebnis
     * 
     * @param AgentGoal $goal Das zu evaluierende Ziel
     * @param AgentHistory $history Die Ausführungshistorie
     * @param string|null $customSuccessMetric Benutzerdefinierte Erfolgsmetrik (optional)
     * @return GoalEvaluation Die gespeicherte Evaluation
     */
    public function evaluateGoal(AgentGoal $goal, AgentHistory $history, ?string $customSuccessMetric = null): GoalEvaluation
    {
        $this->logger->info('Evaluating goal execution', [
            'goal_id' => $goal->getId(),
            'history_id' => $history->getId(),
        ]);

        // Hole die Details aus der History
        $details = json_decode($history->getDetails() ?? '{}', true) ?? [];
        $result = $details['result'] ?? '';

        // Bestimme die Erfolgsmetrik
        $successMetric = $customSuccessMetric ?? $goal->getSuccessMetric();

        // Führe die Evaluation durch
        $evaluation = $this->performEvaluation($goal, $result, $successMetric);

        // Speichere die Evaluation
        $evaluation->setGoal($goal);
        $evaluation->setAgentHistory($history);
        $evaluation->setAgentHistoryId($history->getId());
        $evaluation->setGoalId($goal->getId());
        $evaluation->setEvaluatedBy('system');

        $this->evaluationRepo->save($evaluation, true);

        // Aktualisiere das Ziel mit der letzten Evaluation
        $this->updateGoalWithEvaluation($goal, $evaluation);

        $this->logger->info('Goal evaluation completed', [
            'goal_id' => $goal->getId(),
            'success' => $evaluation->isSuccess(),
            'score' => $evaluation->getScore(),
        ]);

        return $evaluation;
    }

    /**
     * Führe die eigentliche Evaluation durch
     */
    private function performEvaluation(AgentGoal $goal, string $result, ?string $successMetric): GoalEvaluation
    {
        $evaluation = new GoalEvaluation();

        // Falls keine spezifische Metrik definiert ist, nutze generische Evaluation
        if (empty($successMetric)) {
            return $this->performGenericEvaluation($goal, $result);
        }

        // Versuche, eine LLM-gestützte Evaluation durchzuführen
        try {
            return $this->performLLMEvaluation($goal, $result, $successMetric);
        } catch (\Exception $e) {
            $this->logger->warning('LLM evaluation failed, falling back to generic', [
                'error' => $e->getMessage(),
            ]);
            return $this->performGenericEvaluation($goal, $result);
        }
    }

    /**
     * Generische Evaluation basierend auf dem Ergebnis
     */
    private function performGenericEvaluation(AgentGoal $goal, string $result): GoalEvaluation
    {
        $evaluation = new GoalEvaluation();

        // Einfache Heuristik: Prüfe ob das Ergebnis nicht leer ist
        $success = !empty(trim($result));
        $score = $success ? 1.0 : 0.0;

        // Generiere Feedback
        if ($success) {
            $feedback = sprintf('Ziel "%s" wurde erfolgreich ausgeführt.', $goal->getTitle());
        } else {
            $feedback = sprintf('Ziel "%s" lieferte kein Ergebnis.', $goal->getTitle());
        }

        $evaluation->setSuccess($success);
        $evaluation->setScore($score);
        $evaluation->setFeedback($feedback);
        $evaluation->setEvaluationDetails([
            'type' => 'generic',
            'result_length' => strlen($result),
        ]);

        return $evaluation;
    }

    /**
     * LLM-gestützte Evaluation
     */
    private function performLLMEvaluation(AgentGoal $goal, string $result, string $successMetric): GoalEvaluation
    {
        $evaluation = new GoalEvaluation();

        // Baue den Evaluation-Prompt
        $prompt = $this->buildEvaluationPrompt($goal, $result, $successMetric);

        // Frage den Orchestrator
        $userIdentifier = $goal->getUserIdentifier();
        $response = $this->orchestratorDialogService->ask($prompt, $userIdentifier);

        // Parse die Antwort (erwartet JSON)
        $parsedResponse = $this->parseEvaluationResponse($response);

        $evaluation->setSuccess($parsedResponse['success'] ?? false);
        $evaluation->setScore($parsedResponse['score'] ?? null);
        $evaluation->setFeedback($parsedResponse['feedback'] ?? '');
        $evaluation->setEvaluationDetails([
            'type' => 'llm',
            'success_metric' => $successMetric,
            'raw_response' => $response,
        ]);

        return $evaluation;
    }

    /**
     * Baue den Evaluation-Prompt
     */
    private function buildEvaluationPrompt(AgentGoal $goal, string $result, string $successMetric): string
    {
        $prompt = sprintf("Evaluiere das folgende Ergebnis gegen die Erfolgsmetrik:\n\n", $successMetric);
        $prompt .= sprintf("Ziel: %s\n", $goal->getTitle());
        $prompt .= sprintf("Erfolgsmetrik: %s\n\n", $successMetric);
        $prompt .= sprintf("Ergebnis:\n%s\n\n", $result);
        $prompt .= "Antworte mit einem JSON-Objekt:\n";
        $prompt .= "{\n";
        $prompt .= "  \"success\": true/false,  // War das Ziel erfolgreich?\n";
        $prompt .= "  \"score\": 0.0-1.0,      // Bewertung (0 = komplett gescheitert, 1 = perfekt)\n";
        $prompt .= "  \"feedback\": \"...\"   // Detailliertes Feedback zur Evaluation\n";
        $prompt .= "}\n";

        return $prompt;
    }

    /**
     * Parse die Evaluation-Antwort
     */
    private function parseEvaluationResponse(string $response): array
    {
        // Versuche JSON zu parsen
        $decoded = json_decode($response, true);
        
        if (is_array($decoded)) {
            return [
                'success' => $decoded['success'] ?? false,
                'score' => isset($decoded['score']) ? (float) $decoded['score'] : null,
                'feedback' => $decoded['feedback'] ?? '',
            ];
        }

        // Falls kein JSON, versuche zu extrahieren
        return [
            'success' => false,
            'score' => 0.0,
            'feedback' => 'Konnte Evaluation nicht parsen',
        ];
    }

    /**
     * Aktualisiere das Ziel mit der Evaluation
     */
    private function updateGoalWithEvaluation(AgentGoal $goal, GoalEvaluation $evaluation): void
    {
        // Aktualisiere das letzte Evaluationsdatum
        $goal->setLastEvaluation(new \DateTimeImmutable());
        
        // Speichere die letzte Bewertung
        $goal->setLastEvaluationScore($evaluation->getScore());

        $this->goalRepo->save($goal, true);
    }

    /**
     * Evaluiert mehrere Ziele auf einmal
     */
    public function evaluateMultipleGoals(array $goalHistoryPairs): array
    {
        $evaluations = [];

        foreach ($goalHistoryPairs as $pair) {
            if ($pair['goal'] instanceof AgentGoal && $pair['history'] instanceof AgentHistory) {
                $evaluations[] = $this->evaluateGoal(
                    $pair['goal'],
                    $pair['history'],
                    $pair['success_metric'] ?? null
                );
            }
        }

        return $evaluations;
    }

    /**
     * Hole die letzte Evaluation für ein Ziel
     */
    public function getLastEvaluationForGoal(int $goalId): ?GoalEvaluation
    {
        $evaluations = $this->evaluationRepo->findByGoal($goalId);
        
        return !empty($evaluations) ? $evaluations[0] : null;
    }

    /**
     * Hole den durchschnittlichen Score für ein Ziel
     */
    public function getAverageScoreForGoal(int $goalId): ?float
    {
        return $this->evaluationRepo->getAverageScoreForGoal($goalId);
    }

    /**
     * Hole die Erfolgsquote für ein Ziel
     */
    public function getSuccessRateForGoal(int $goalId): float
    {
        return $this->evaluationRepo->getSuccessRateForGoal($goalId);
    }
}
