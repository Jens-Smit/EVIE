<?php

namespace App\MessageHandler;

use App\AI\Agent\OrchestratorDialogService;
use App\AI\Security\AuditLogger;
use App\Entity\AgentGoal;
use App\Entity\AgentHistory;
use App\Entity\UserProfile;
use App\Message\RunAgentGoalMessage;
use App\Repository\AgentGoalRepository;
use App\Repository\AgentHistoryRepository;
use App\Repository\UserProfileRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handler für die autonome Ausführung von Agent-Zielen.
 * Empfängt RunAgentGoalMessage und führt das Ziel als System-Prompt aus.
 * Ergebnisse werden in AgentHistory gespeichert und für Briefings verfügbar gemacht.
 */
#[AsMessageHandler]
class RunAgentGoalHandler
{
    public function __construct(
        private OrchestratorDialogService $orchestratorDialogService,
        private AgentGoalRepository $agentGoalRepo,
        private AgentHistoryRepository $agentHistoryRepo,
        private UserProfileRepository $userProfileRepo,
        private AuditLogger $auditLogger,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Verarbeitet eine RunAgentGoalMessage
     */
    public function __invoke(RunAgentGoalMessage $message): void
    {
        $goalId = $message->getGoalId();
        $userIdentifier = $message->getUserIdentifier();
        $goalTitle = $message->getGoalTitle();
        $capabilityConstraints = $message->getCapabilityConstraints();

        $this->logger->info('RunAgentGoalHandler: Starte autonome Ausführung', [
            'goal_id' => $goalId,
            'user_identifier' => $userIdentifier,
            'goal_title' => $goalTitle,
        ]);

        try {
            // Hole das Ziel aus der Datenbank
            $goal = $this->agentGoalRepo->find($goalId);
            
            if (null === $goal) {
                $this->logger->error('Ziel nicht gefunden', [
                    'goal_id' => $goalId,
                ]);
                return;
            }

            // Prüfe ob das Ziel aktiv und genehmigt ist
            if (!$goal->isActiveAndDue()) {
                $this->logger->info('Ziel ist nicht aktiv oder fällig', [
                    'goal_id' => $goalId,
                    'status' => $goal->getStatus(),
                    'is_approved' => $goal->isApproved(),
                ]);
                return;
            }

            // Hole UserProfile
            $userProfile = $this->userProfileRepo->findOneBy(['userIdentifier' => $userIdentifier]);
            
            if (null === $userProfile) {
                $this->logger->error('UserProfile nicht gefunden', [
                    'user_identifier' => $userIdentifier,
                ]);
                return;
            }

            // Baue System-Prompt für das Ziel
            $systemPrompt = $this->buildGoalPrompt($goal, $capabilityConstraints);

            // Führe das Ziel aus
            $this->logger->info('Führe Ziel als System-Prompt aus', [
                'goal_id' => $goalId,
                'prompt_length' => strlen($systemPrompt),
            ]);

            $result = $this->orchestratorDialogService->ask($systemPrompt, $userIdentifier);

            // Speichere das Ergebnis
            $this->saveExecutionResult($goal, $result, $userProfile);

            // Aktualisiere das Ziel
            $this->updateGoalAfterExecution($goal);

            // Audit-Log
            $this->auditLogger->log('agent_goal_execution', $userProfile->getUser(), $goalId, 'AgentGoal', [
                'goal_title' => $goalTitle,
                'result_length' => strlen($result),
            ], 'success', 'Autonome Ziel-Ausführung erfolgreich');

            $this->logger->info('Ziel erfolgreich ausgeführt', [
                'goal_id' => $goalId,
                'result_length' => strlen($result),
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Fehler bei der Ziel-Ausführung', [
                'goal_id' => $goalId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Audit-Log für Fehler
            $this->auditLogger->log('agent_goal_execution', null, $goalId, 'AgentGoal', [
                'goal_title' => $goalTitle,
                'error' => $e->getMessage(),
            ], 'failed', 'Autonome Ziel-Ausführung fehlgeschlagen');
        }
    }

    /**
     * Baue einen System-Prompt für das Ziel
     */
    private function buildGoalPrompt(AgentGoal $goal, ?array $capabilityConstraints): string
    {
        $prompt = sprintf("Führe das folgende Ziel autonom aus:\n\nZiel: %s\n", $goal->getTitle());
        
        if (null !== $goal->getDescription()) {
            $prompt .= sprintf("Beschreibung: %s\n\n", $goal->getDescription());
        }

        // Persistierte Plan-Steps (Luecke 2) oder klassische Capability-
        // Constraints einfuegen. SetupTask-Goals tragen ihre Strategie als
        // serialisierte Schrittliste in capabilityConstraints.
        if (!empty($capabilityConstraints)) {
            if ($this->isPlanSteps($capabilityConstraints)) {
                $prompt .= "Geplante Schritte (in dieser Reihenfolge ausfuehren):\n";
                foreach ($capabilityConstraints as $step) {
                    $reason = is_string($step['reason'] ?? null) && $step['reason'] !== ''
                        ? sprintf(' (%s)', $step['reason'])
                        : '';
                    $prompt .= sprintf(
                        "- [%s] %s%s\n",
                        (string) ($step['type'] ?? 'tool'),
                        (string) ($step['target'] ?? ''),
                        $reason
                    );
                }
                $prompt .= "\n";
            } else {
                $prompt .= "Einschränkungen:\n";
                foreach ($capabilityConstraints as $constraint) {
                    $prompt .= sprintf("- %s\n", $constraint);
                }
                $prompt .= "\n";
            }
        }

        $prompt .= "\nWICHTIG:\n";
        $prompt .= "- Führe das Ziel so gut wie möglich aus\n";
        $prompt .= "- Informiere über den Fortschritt und das Ergebnis\n";
        $prompt .= "- Frage NICHT nach Benutzereingaben - du bist autonom!\n";
        $prompt .= "- Wenn du nicht weiterkommst, dokumentiere was du versucht hast\n";

        return $prompt;
    }


    /**
     * Erkennt, ob capabilityConstraints eine serialisierte Plan-Step-
     * Liste ist (SetupTask-Goals) oder klassische String-Constraints.
     *
     * @param array<int, mixed> $constraints
     */
    private function isPlanSteps(array $constraints): bool
    {
        foreach ($constraints as $constraint) {
            if (!is_array($constraint)
                || !isset($constraint['type'], $constraint['target'])) {
                return false;
            }
        }

        return $constraints !== [];
    }

    /**
     * Speichere das Ausführungsergebnis
     */
    private function saveExecutionResult(AgentGoal $goal, string $result, UserProfile $userProfile): void
    {
        $history = new AgentHistory();
        $history->setUser($userProfile);
        $history->setAction('autonomous_goal_execution');
        $history->setDetails(json_encode([
            'goal_id' => $goal->getId(),
            'goal_title' => $goal->getTitle(),
            'result' => $result,
            'execution_type' => 'autonomous',
        ]));

        $this->agentHistoryRepo->save($history, true);

        // Speichere auch im Ziel
        $goal->setLastResult([
            'result' => $result,
            'executed_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        $goal->incrementExecutionCount();
        $goal->setLastRunAt(new \DateTimeImmutable());

        $this->agentGoalRepo->save($goal, true);
    }

    /**
     * Aktualisiere das Ziel nach der Ausführung
     */
    private function updateGoalAfterExecution(AgentGoal $goal): void
    {
        // Bei Cron-Expressions wird der naechste Lauf berechnet. Ohne Cron
        // (z.B. SetupTask-Goals) wird nextRunAt nach der Ausfuehrung auf
        // null gesetzt, damit faellige Einmal-Ausfuehrungen nicht erneut
        // durch den Scheduler dispatch werden.
        $nextRunAt = $goal->calculateNextRunAt();
        if ($goal->getCronExpression() !== null && null !== $nextRunAt) {
            $goal->setNextRunAt($nextRunAt);
        } elseif ($goal->getCronExpression() === null) {
            $goal->setNextRunAt(null);
        }

        $this->agentGoalRepo->save($goal, true);
    }
}
