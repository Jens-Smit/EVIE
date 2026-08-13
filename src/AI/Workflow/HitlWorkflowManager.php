<?php

namespace App\AI\Workflow;

use App\Entity\ToolDefinition;
use App\Entity\User;
use App\AI\Skills\Tool\DynamicTool;
use App\AI\Skills\DynamicSkillRegistry;
use App\AI\Security\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * HITL-Workflow-Manager - Vollständiger Workflow:
 * blocked execution -> approval -> resume original agent task
 */
class HitlWorkflowManager
{
    private array $pendingExecutions = [];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private DynamicSkillRegistry $skillRegistry,
        private AuditLogger $auditLogger,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Blockiere eine Execution und speichere den Kontext für Resume
     */
    public function blockExecution(
        string $executionId,
        DynamicTool $tool,
        array $parameters,
        User $user,
        string $originalRequest
    ): PendingExecution
    {
        $pendingExecution = new PendingExecution(
            $executionId,
            $tool,
            $parameters,
            $user,
            $originalRequest
        );

        $this->pendingExecutions[$executionId] = $pendingExecution;
        
        $this->logger->info('Execution blockiert und für HITL-Approval gespeichert', [
            'execution_id' => $executionId,
            'tool_name' => $tool->getName(),
            'user_id' => $user->getId()
        ]);

        $this->auditLogger->log(
            'hitl_execution_blocked',
            $user,
            $tool->getDefinition()?->getId(),
            'ToolDefinition',
            [
                'execution_id' => $executionId,
                'tool_name' => $tool->getName(),
                'original_request' => $originalRequest
            ],
            'warning'
        );

        return $pendingExecution;
    }

    /**
     * Approve eine blockierte Execution
     */
    public function approveExecution(string $executionId, User $approver, ?string $reason = null): ?ExecutionResult
    {
        if (!isset($this->pendingExecutions[$executionId])) {
            $this->logger->warning('Pending Execution nicht gefunden', ['execution_id' => $executionId]);
            return null;
        }

        $pendingExecution = $this->pendingExecutions[$executionId];
        
        // Approve das Tool (falls noch pending)
        $definition = $pendingExecution->getTool()->getDefinition();
        if ($definition && $definition->getStatus() === 'pending') {
            $definition->setStatus('approved');
            $this->entityManager->flush();
            
            // Registriere das Tool
            $this->skillRegistry->registerTool($pendingExecution->getTool(), $definition);
        }

        // Führe die Execution aus
        try {
            $result = $this->executeTool(
                $pendingExecution->getTool(),
                $pendingExecution->getParameters()
            );

            $this->logger->info('HITL-Execution approved und ausgeführt', [
                'execution_id' => $executionId,
                'tool_name' => $pendingExecution->getTool()->getName(),
                'approver_id' => $approver->getId()
            ]);

            $this->auditLogger->logHitlDecision(
                $definition?->getId() ?? 0,
                $pendingExecution->getTool()->getName(),
                $approver,
                'approved',
                $reason
            );

            // Entferne aus pending
            unset($this->pendingExecutions[$executionId]);

            return new ExecutionResult(
                true,
                $result,
                null,
                $pendingExecution->getOriginalRequest()
            );

        } catch (\Exception $e) {
            $this->logger->error('Fehler bei HITL-Execution', [
                'execution_id' => $executionId,
                'error' => $e->getMessage()
            ]);

            $this->auditLogger->logHitlDecision(
                $definition?->getId() ?? 0,
                $pendingExecution->getTool()->getName(),
                $approver,
                'rejected',
                'Execution fehlgeschlagen: ' . $e->getMessage()
            );

            return new ExecutionResult(
                false,
                null,
                $e->getMessage(),
                $pendingExecution->getOriginalRequest()
            );
        }
    }

    /**
     * Reject eine blockierte Execution
     */
    public function rejectExecution(string $executionId, User $rejecter, string $reason): bool
    {
        if (!isset($this->pendingExecutions[$executionId])) {
            $this->logger->warning('Pending Execution nicht gefunden', ['execution_id' => $executionId]);
            return false;
        }

        $pendingExecution = $this->pendingExecutions[$executionId];
        
        $this->logger->info('HITL-Execution rejected', [
            'execution_id' => $executionId,
            'tool_name' => $pendingExecution->getTool()->getName(),
            'rejecter_id' => $rejecter->getId(),
            'reason' => $reason
        ]);

        $this->auditLogger->logHitlDecision(
            $pendingExecution->getTool()->getDefinition()?->getId() ?? 0,
            $pendingExecution->getTool()->getName(),
            $rejecter,
            'rejected',
            $reason
        );

        unset($this->pendingExecutions[$executionId]);
        
        return true;
    }

    /**
     * Hole alle pending Executions
     */
    public function getPendingExecutions(): array
    {
        return $this->pendingExecutions;
    }

    /**
     * Hole eine spezifische pending Execution
     */
    public function getPendingExecution(string $executionId): ?PendingExecution
    {
        return $this->pendingExecutions[$executionId] ?? null;
    }

    /**
     * Führe ein Tool aus
     */
    private function executeTool(DynamicTool $tool, array $parameters): mixed
    {
        // Hier würde die echte Execution stattfinden
        // Für jetzt: Simuliere eine erfolgreiche Ausführung
        return [
            'status' => 'success',
            'tool' => $tool->getName(),
            'parameters' => $parameters,
            'message' => 'Tool erfolgreich ausgeführt nach HITL-Approval'
        ];
    }
}
