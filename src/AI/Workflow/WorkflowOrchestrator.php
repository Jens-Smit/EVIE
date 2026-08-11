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
            $this->updateWorkflowStatus($workflow, 'completed');
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
        $workflow->setAction('workflow_orchestrator');
        $workflow->setDetails(json_encode([
            'agent' => 'workflow_orchestrator',
            'status' => 'running',
            'input' => [
                'task' => $strategy['task'],
                'user_identifier' => $userIdentifier,
            ],
            'output' => [
                'strategy' => $strategy,
            ],
            'metadata' => [
                'workflow_type' => 'multi_step',
                'total_steps' => $strategy['execution_plan']['total_steps'] ?? count($strategy['execution_plan']['steps']),
                'estimated_duration' => $strategy['estimated_duration'],
                'risk_level' => $strategy['risk_assessment']['level'],
            ],
        ], JSON_THROW_ON_ERROR));

        // UserProfile laden bzw. den userIdentifier als Referenz speichern
        // (Der User wird über das Repository zugeordnet)
        $userProfile = $this->historyRepo->findUserByIdentifier($userIdentifier);
        if ($userProfile) {
            $workflow->setUser($userProfile);
        }

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
                    $this->updateWorkflowStatus($workflow, 'failed');
                    $this->updateWorkflowError($workflow, $e->getMessage());
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
        $details = $this->getWorkflowDetailsArray($workflow);
        $details['metadata']['executed_steps'] = $executedSteps;
        $details['metadata']['step_results'] = $results;
        $details['metadata']['last_updated'] = (new \DateTimeImmutable())->format(DATE_ATOM);
        $details['output']['results'] = $results;

        $workflow->setDetails(json_encode($details, JSON_THROW_ON_ERROR));

        $this->historyRepo->save($workflow, true);
    }

    /**
     * Speichert die Ergebnisse eines Workflows
     */
    private function saveResults(AgentHistory $workflow, array $results): void
    {
        $details = $this->getWorkflowDetailsArray($workflow);
        $details['metadata']['final_results'] = $results;
        $details['metadata']['completed_at'] = (new \DateTimeImmutable())->format(DATE_ATOM);
        $details['output']['results'] = $results;
        $details['executed_at'] = (new \DateTimeImmutable())->format(DATE_ATOM);

        $workflow->setDetails(json_encode($details, JSON_THROW_ON_ERROR));

        $this->historyRepo->save($workflow, true);
    }

    /**
     * Aktualisiert den Status eines Workflows
     */
    private function updateWorkflowStatus(AgentHistory $workflow, string $status): void
    {
        $details = $this->getWorkflowDetailsArray($workflow);
        $details['status'] = $status;
        $workflow->setDetails(json_encode($details, JSON_THROW_ON_ERROR));
    }

    /**
     * Aktualisiert den Fehler eines Workflows
     */
    private function updateWorkflowError(AgentHistory $workflow, string $error): void
    {
        $details = $this->getWorkflowDetailsArray($workflow);
        $details['error'] = $error;
        $workflow->setDetails(json_encode($details, JSON_THROW_ON_ERROR));
    }

    /**
     * Liest die Workflow-Details aus der Entity
     */
    private function getWorkflowDetailsArray(AgentHistory $workflow): array
    {
        return json_decode($workflow->getDetails() ?? '{}', true) ?? [];
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
            ->where('ah.action = :agent')
            ->setParameter('agent', 'workflow_orchestrator');

        if ($userIdentifier) {
            $queryBuilder->join('ah.user', 'u')
                ->andWhere('u.userIdentifier = :user')
                ->setParameter('user', $userIdentifier);
        }

        $workflows = $queryBuilder
            ->orderBy('ah.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return array_map(function($workflow) {
            $details = $this->getWorkflowDetailsArray($workflow);
            return [
                'id' => $workflow->getId(),
                'status' => $details['status'] ?? null,
                'task' => $details['input']['task'] ?? 'Unbekannte Aufgabe',
                'progress' => $this->calculateProgress($workflow),
                'estimated_duration' => $details['metadata']['estimated_duration'] ?? 'Unbekannt',
                'risk_level' => $details['metadata']['risk_level'] ?? 'low',
                'created_at' => $workflow->getCreatedAt()->format('Y-m-d H:i:s'),
            ];
        }, $workflows);
    }

    /**
     * Berechnet den Fortschritt eines Workflows
     */
    private function calculateProgress(AgentHistory $workflow): int
    {
        $details = $this->getWorkflowDetailsArray($workflow);
        $metadata = $details['metadata'] ?? [];
        
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

        if (!$workflow || $workflow->getAction() !== 'workflow_orchestrator') {
            return null;
        }

        $details = $this->getWorkflowDetailsArray($workflow);
        $metadata = $details['metadata'] ?? [];
        $output = $details['output'] ?? [];

        return [
            'id' => $workflow->getId(),
            'status' => $details['status'] ?? null,
            'task' => $details['input']['task'] ?? 'Unbekannte Aufgabe',
            'user' => $workflow->getUser()?->getUserIdentifier(),
            'progress' => $this->calculateProgress($workflow),
            'total_steps' => $metadata['total_steps'] ?? 0,
            'executed_steps' => $metadata['executed_steps'] ?? [],
            'results' => $output['results'] ?? [],
            'estimated_duration' => $metadata['estimated_duration'] ?? 'Unbekannt',
            'risk_level' => $metadata['risk_level'] ?? 'low',
            'created_at' => $workflow->getCreatedAt()->format('Y-m-d H:i:s'),
            'executed_at' => $details['executed_at'] ?? null,
            'error' => $details['error'] ?? null,
        ];
    }

    /**
     * Bricht einen Workflow ab
     */
    public function cancelWorkflow(int $workflowId, string $reason = null): bool
    {
        $workflow = $this->historyRepo->find($workflowId);

        if (!$workflow || $workflow->getAction() !== 'workflow_orchestrator') {
            return false;
        }

        $details = $this->getWorkflowDetailsArray($workflow);
        if (($details['status'] ?? null) !== 'running') {
            return false;
        }

        $this->updateWorkflowStatus($workflow, 'cancelled');
        $this->updateWorkflowError($workflow, $reason ?? 'Workflow manuell abgebrochen');
        $details = $this->getWorkflowDetailsArray($workflow);
        $details['executed_at'] = (new \DateTimeImmutable())->format(DATE_ATOM);
        $workflow->setDetails(json_encode($details, JSON_THROW_ON_ERROR));

        $this->historyRepo->save($workflow, true);

        $this->logger->info('Workflow abgebrochen', [
            'workflow_id' => $workflowId,
            'reason' => $reason,
        ]);

        return true;
    }
}