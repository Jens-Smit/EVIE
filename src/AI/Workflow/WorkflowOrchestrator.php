<?php
// src/AI/Workflow/WorkflowOrchestrator.php

namespace App\AI\Workflow;

use App\AI\Strategy\StrategyManager;
use App\AI\Agent\SubAgentFactory;
use App\Entity\AgentHistory;
use App\Repository\AgentHistoryRepository;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;

/**
 * Orchestriert die Ausführung von Workflows.
 * Führt die einzelnen Schritte eines Ausführungsplans aus und verwaltet
 * den Zustand der Ausführung.
 */
class WorkflowOrchestrator
{
    public function __construct(
        private StrategyManager $strategyManager,
        private SubAgentFactory $subAgentFactory,
        private AgentHistoryRepository $historyRepo,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Führt einen kompletten Workflow aus
     */
    public function executeWorkflow(string $taskDescription, string $userIdentifier): array
    {
        $this->logger->info('Starte Workflow-Ausführung', [
            'task' => substr($taskDescription, 0, 100),
            'user' => $userIdentifier,
        ]);

        try {
            // 1. Strategie entwickeln
            $strategy = $this->strategyManager->developStrategy($taskDescription, $userIdentifier);

            // 2. Workflow in der Datenbank speichern
            $workflow = $this->createWorkflowEntity($strategy, $userIdentifier);

            // 3. Workflow ausführen
            $results = $this->executeSteps($strategy['execution_plan']['steps'], $workflow);

            // 4. Ergebnisse speichern
            $this->saveResults($workflow, $results);

            // 5. Workflow als erfolgreich markieren
            $workflow->setStatus('completed');
            $this->historyRepo->save($workflow, true);

            $this->logger->info('Workflow erfolgreich abgeschlossen', [
                'workflow_id' => $workflow->getId(),
                'steps_completed' => count($results),
            ]);

            return [
                'workflow_id' => $workflow->getId(),
                'strategy' => $strategy,
                'results' => $results,
                'status' => 'completed',
                'duration' => $this->calculateTotalDuration($results),
            ];

        } catch (\Exception $e) {
            $this->logger->error('Workflow-Ausführung fehlgeschlagen', [
                'error' => $e->getMessage(),
                'task' => substr($taskDescription, 0, 100),
            ]);

            return [
                'workflow_id' => null,
                'strategy' => null,
                'results' => [],
                'status' => 'failed',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Erstellt eine Workflow-Entität
     */
    private function createWorkflowEntity(array $strategy, string $userIdentifier): AgentHistory
    {
        $workflow = new AgentHistory();
        $workflow->setAgentName('workflow_orchestrator');
        $workflow->setInput([
            'task' => $strategy['task'],
            'user_identifier' => $userIdentifier,
        ]);
        $workflow->setOutput([
            'strategy' => $strategy,
        ]);
        $workflow->setStatus('running');
        $workflow->setUserProfile($userIdentifier);
        $workflow->setMetadata([
            'workflow_type' => 'multi_step',
            'total_steps' => $strategy['execution_plan']['total_steps'],
            'estimated_duration' => $strategy['estimated_duration'],
            'risk_level' => $strategy['risk_assessment']['level'],
        ]);

        $this->historyRepo->save($workflow, true);

        return $workflow;
    }

    /**
     * Führt die einzelnen Schritte eines Workflows aus
     */
    private function executeSteps(array $steps, AgentHistory $workflow): array
    {
        $results = [];
        $executedSteps = [];

        foreach ($steps as $step) {
            try {
                $result = $this->executeStep($step, $workflow);
                $results[] = [
                    'step' => $step['step'],
                    'description' => $step['description'],
                    'result' => $result,
                    'status' => 'success',
                    'timestamp' => (new \DateTimeImmutable())->format(DATE_ATOM),
                    'duration' => $step['estimated_time'],
                ];
                $executedSteps[] = $step['step'];

                // Speichere Zwischenergebnis
                $this->updateWorkflowProgress($workflow, $executedSteps, $results);

            } catch (\Exception $e) {
                $results[] = [
                    'step' => $step['step'],
                    'description' => $step['description'],
                    'error' => $e->getMessage(),
                    'status' => 'failed',
                    'timestamp' => (new \DateTimeImmutable())->format(DATE_ATOM),
                ];

                // Prüfe Fallback-Strategie
                if ($this->shouldContinueOnFailure($step, $e)) {
                    $this->logger->warning('Schritt fehlgeschlagen, fahre fort', [
                        'step' => $step['step'],
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                } else {
                    // Workflow abbrechen
                    $workflow->setStatus('failed');
                    $workflow->setError($e->getMessage());
                    $this->historyRepo->save($workflow, true);
                    throw $e;
                }
            }
        }

        return $results;
    }

    /**
     * Führt einen einzelnen Schritt aus
     */
    private function executeStep(array $step, AgentHistory $workflow): mixed
    {
        $this->logger->debug('Führe Schritt aus', [
            'step' => $step['step'],
            'description' => $step['description'],
            'agent' => $step['agent'],
            'tool' => $step['tool'],
        ]);

        if ($step['agent']) {
            // Sub-Agent aufrufen
            $agent = $this->subAgentFactory->getAvailableSubAgents()[$step['agent']] ?? null;

            if (!$agent) {
                throw new \RuntimeException("Agent {$step['agent']} nicht verfügbar");
            }

            // Aufgabe an den Agenten delegieren
            $messages = new MessageBag(Message::ofUser($step['description']));
            $result = $agent->call($messages);

            return $result->getContent();
        }

        // Oder Tool direkt ausführen
        if ($step['tool']) {
            return $this->executeTool($step['tool'], $step['parameters'] ?? []);
        }

        // Standard: Keine spezifische Aktion
        return ['status' => 'completed', 'message' => 'Schritt erfolgreich ausgeführt'];
    }

    /**
     * Führt ein Tool aus
     */
    private function executeTool(string $toolName, array $parameters = []): mixed
    {
        $this->logger->debug('Führe Tool aus', [
            'tool' => $toolName,
            'parameters' => $parameters,
        ]);

        // Hier würde das Tool ausgeführt werden
        // Für jetzt: Platzhalter
        return [
            'tool' => $toolName,
            'parameters' => $parameters,
            'status' => 'executed',
            'result' => 'Tool erfolgreich ausgeführt',
        ];
    }

    /**
     * Aktualisiert den Workflow-Fortschritt
     */
    private function updateWorkflowProgress(AgentHistory $workflow, array $executedSteps, array $results): void
    {
        $metadata = $workflow->getMetadata() ?? [];
        $metadata['executed_steps'] = $executedSteps;
        $metadata['step_results'] = $results;
        $metadata['last_updated'] = (new \DateTimeImmutable())->format(DATE_ATOM);

        $workflow->setMetadata($metadata);
        $workflow->setOutput(['results' => $results]);

        $this->historyRepo->save($workflow, true);
    }

    /**
     * Speichert die Ergebnisse eines Workflows
     */
    private function saveResults(AgentHistory $workflow, array $results): void
    {
        $metadata = $workflow->getMetadata() ?? [];
        $metadata['final_results'] = $results;
        $metadata['completed_at'] = (new \DateTimeImmutable())->format(DATE_ATOM);

        $workflow->setMetadata($metadata);
        $workflow->setOutput(['results' => $results]);
        $workflow->setExecutedAt(new \DateTimeImmutable());

        $this->historyRepo->save($workflow, true);
    }

    /**
     * Berechnet die Gesamtdauer eines Workflows
     */
    private function calculateTotalDuration(array $results): string
    {
        $totalSeconds = 0;
        foreach ($results as $result) {
            if (isset($result['duration'])) {
                $totalSeconds += $this->parseTimeString($result['duration']);
            }
        }

        return $this->formatDuration($totalSeconds);
    }

    /**
     * Parsed Zeit-Strings (z.B. "30s", "2m", "1h")
     */
    private function parseTimeString(string $timeString): int
    {
        if (preg_match('/(\d+)s/', $timeString, $matches)) {
            return (int)$matches[1];
        }
        if (preg_match('/(\d+)m/', $timeString, $matches)) {
            return (int)$matches[1] * 60;
        }
        if (preg_match('/(\d+)h/', $timeString, $matches)) {
            return (int)$matches[1] * 3600;
        }
        return 10; // Standard: 10 Sekunden
    }

    /**
     * Formatiert Sekunden in lesbare Dauer
     */
    private function formatDuration(int $seconds): string
    {
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

    /**
     * Prüft, ob bei einem Fehler weitergemacht werden soll
     */
    private function shouldContinueOnFailure(array $step, \Exception $e): bool
    {
        // Immer weiter, wenn die Fallback-Strategie es erlaubt
        // Hier könnte man die Strategie aus dem Workflow prüfen
        return true;
    }

    /**
     * Gibt alle aktiven Workflows zurück
     */
    public function getActiveWorkflows(string $userIdentifier = null): array
    {
        $queryBuilder = $this->historyRepo->createQueryBuilder('ah')
            ->where('ah.agentName = :agent')
            ->andWhere('ah.status IN (:statuses)')
            ->setParameter('agent', 'workflow_orchestrator')
            ->setParameter('statuses', ['running', 'pending']);

        if ($userIdentifier) {
            $queryBuilder->andWhere('ah.userProfile = :user')
                ->setParameter('user', $userIdentifier);
        }

        $workflows = $queryBuilder
            ->orderBy('ah.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return array_map(function($workflow) {
            $metadata = $workflow->getMetadata() ?? [];
            return [
                'id' => $workflow->getId(),
                'status' => $workflow->getStatus(),
                'task' => $workflow->getInput()['task'] ?? 'Unbekannte Aufgabe',
                'progress' => $this->calculateProgress($workflow),
                'estimated_duration' => $metadata['estimated_duration'] ?? 'Unbekannt',
                'risk_level' => $metadata['risk_level'] ?? 'low',
                'created_at' => $workflow->getCreatedAt()->format('Y-m-d H:i:s'),
            ];
        }, $workflows);
    }

    /**
     * Berechnet den Fortschritt eines Workflows
     */
    private function calculateProgress(AgentHistory $workflow): int
    {
        $metadata = $workflow->getMetadata() ?? [];
        
        if (isset($metadata['total_steps']) && isset($metadata['executed_steps'])) {
            $total = $metadata['total_steps'];
            $executed = count($metadata['executed_steps']);
            
            return $total > 0 ? intval(($executed / $total) * 100) : 0;
        }

        return 0;
    }

    /**
     * Gibt die Details eines bestimmten Workflows zurück
     */
    public function getWorkflowDetails(int $workflowId): ?array
    {
        $workflow = $this->historyRepo->find($workflowId);

        if (!$workflow || $workflow->getAgentName() !== 'workflow_orchestrator') {
            return null;
        }

        $metadata = $workflow->getMetadata() ?? [];
        $output = $workflow->getOutput() ?? [];

        return [
            'id' => $workflow->getId(),
            'status' => $workflow->getStatus(),
            'task' => $workflow->getInput()['task'] ?? 'Unbekannte Aufgabe',
            'user' => $workflow->getUserProfile(),
            'progress' => $this->calculateProgress($workflow),
            'total_steps' => $metadata['total_steps'] ?? 0,
            'executed_steps' => $metadata['executed_steps'] ?? [],
            'results' => $output['results'] ?? [],
            'estimated_duration' => $metadata['estimated_duration'] ?? 'Unbekannt',
            'risk_level' => $metadata['risk_level'] ?? 'low',
            'created_at' => $workflow->getCreatedAt()->format('Y-m-d H:i:s'),
            'executed_at' => $workflow->getExecutedAt()?->format('Y-m-d H:i:s'),
            'error' => $workflow->getError(),
        ];
    }

    /**
     * Bricht einen Workflow ab
     */
    public function cancelWorkflow(int $workflowId, string $reason = null): bool
    {
        $workflow = $this->historyRepo->find($workflowId);

        if (!$workflow || $workflow->getAgentName() !== 'workflow_orchestrator') {
            return false;
        }

        if ($workflow->getStatus() !== 'running') {
            return false;
        }

        $workflow->setStatus('cancelled');
        $workflow->setError($reason ?? 'Workflow manuell abgebrochen');
        $workflow->setExecutedAt(new \DateTimeImmutable());

        $this->historyRepo->save($workflow, true);

        $this->logger->info('Workflow abgebrochen', [
            'workflow_id' => $workflowId,
            'reason' => $reason,
        ]);

        return true;
    }
}
