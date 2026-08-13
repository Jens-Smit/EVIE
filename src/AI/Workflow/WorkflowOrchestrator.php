<?php

namespace AppAIWorkflow;

use AppEntityToolDefinition;
use AppEntityUserProfile;
use AppAIRagContextInjector;
use AppAISkillsToolDynamicTool;
use AppAISkillsDynamicToolFactory;
use AppAISkillsToolDynamicToolExecutor;
use AppAISkillsToolDefinitionGenerator;
use AppAISkillsDynamicSkillRegistry;
use AppAIWorkflowHitlWorkflowManager;
use PsrLogLoggerInterface;
use SymfonyComponentSecurityCoreUserUserInterface;

/**
 * WorkflowOrchestrator - Haupt-Orchestrator mit RAG-Integration
 */
class WorkflowOrchestrator
{
    private ?UserInterface $currentUser = null;
    private ?UserProfile $currentUserProfile = null;

    public function __construct(
        private DynamicToolFactory $toolFactory,
        private DynamicToolExecutor $toolExecutor,
        private ToolDefinitionGenerator $toolDefinitionGenerator,
        private DynamicSkillRegistry $skillRegistry,
        private HitlWorkflowManager $hitlWorkflowManager,
        private ContextInjector $contextInjector,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Setze den aktuellen User
     */
    public function setCurrentUser(?UserInterface $user, ?UserProfile $profile = null): void
    {
        $this->currentUser = $user;
        $this->currentUserProfile = $profile;
    }

    /**
     * Verarbeite eine User-Anfrage
     */
    public function processRequest(string $request): array
    {
        try {
            // 1. Prüfe ob ein Tool verfügbar ist
            $tool = $this->findMatchingTool($request);
            
            if ($tool) {
                // Tool gefunden - führe es aus
                return $this->executeTool($tool, $request);
            }

            // 2. Kein Tool gefunden - generiere neues Tool
            return $this->generateAndExecuteTool($request);

        } catch (Exception $e) {
            $this->logger->error('Fehler bei Request-Verarbeitung', [
                'request' => $request,
                'error' => $e->getMessage()
            ]);

            return [
                'status' => 'error',
                'message' => 'Fehler bei der Verarbeitung: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Finde ein passendes Tool
     */
    private function findMatchingTool(string $request): ?DynamicTool
    {
        $tools = $this->toolFactory->getAllTools();
        
        foreach ($tools as $tool) {
            // Einfache Matching-Logik (könnte durch LLM verbessert werden)
            $toolName = strtolower($tool->getName());
            $requestLower = strtolower($request);
            
            if (str_contains($requestLower, $toolName)) {
                return $tool;
            }
        }

        return null;
    }

    /**
     * Führe ein Tool aus
     */
    private function executeTool(DynamicTool $tool, string $request): array
    {
        // Prüfe ob Tool HITL erfordert
        if ($tool->requiresHitl()) {
            // Blockiere Execution für HITL
            $executionId = uniqid('exec_', true);
            $pendingExecution = $this->hitlWorkflowManager->blockExecution(
                $executionId,
                $tool,
                [],
                $this->currentUser ?? throw new RuntimeException('User erforderlich für HITL'),
                $request
            );

            return [
                'status' => 'pending_hitl',
                'execution_id' => $executionId,
                'tool_name' => $tool->getName(),
                'message' => 'Tool erfordert HITL-Approval',
                'pending_execution' => $pendingExecution->toArray()
            ];
        }

        // Führe Tool aus
        $parameters = $this->extractParameters($request, $tool->getSchema());
        $executionResult = $this->toolExecutor->execute($tool, $parameters);

        if (!$executionResult->isSuccess()) {
            return [
                'status' => 'error',
                'tool_name' => $tool->getName(),
                'error' => $executionResult->getError()
            ];
        }

        return [
            'status' => 'success',
            'tool_name' => $tool->getName(),
            'result' => $executionResult->getResult()
        ];
    }

    /**
     * Generiere und führe ein neues Tool aus
     */
    private function generateAndExecuteTool(string $request): array
    {
        // Generiere Tool-Definition
        $definition = $this->toolDefinitionGenerator->generateFromRequest($request);
        
        if (!$definition) {
            return [
                'status' => 'no_tool_found',
                'message' => 'Kein passendes Tool gefunden und Generierung fehlgeschlagen'
            ];
        }

        // Speichere als pending
        $definition->setStatus('pending');
        $this->entityManager->persist($definition);
        $this->entityManager->flush();

        // Blockiere für HITL
        $executionId = uniqid('exec_', true);
        $tool = $this->toolFactory->createFromDefinition($definition);
        
        $pendingExecution = $this->hitlWorkflowManager->blockExecution(
            $executionId,
            $tool,
            [],
            $this->currentUser ?? throw new RuntimeException('User erforderlich für HITL'),
            $request
        );

        return [
            'status' => 'tool_generated_pending_hitl',
            'execution_id' => $executionId,
            'tool_definition_id' => $definition->getId(),
            'tool_name' => $definition->getName(),
            'message' => 'Neues Tool generiert, HITL-Approval erforderlich',
            'pending_execution' => $pendingExecution->toArray()
        ];
    }

    /**
     * Verarbeite eine Anfrage mit RAG-Kontext
     */
    public function processRequestWithRag(string $request): array
    {
        // Injiziere Kontext in die Anfrage
        $enhancedRequest = $this->contextInjector->inject(
            $request,
            $request,
            ['content_types' => ['user_profile', 'knowledge']]
        );

        // Verarbeite die erweiterte Anfrage
        return $this->processRequest($enhancedRequest);
    }

    /**
     * Erstelle System-Prompt mit RAG-Kontext
     */
    public function createSystemPromptWithRag(string $basePrompt, string $request): string
    {
        if ($this->currentUserProfile) {
            return $this->contextInjector->injectForSystemPrompt(
                $basePrompt,
                $request,
                ['content_types' => ['user_profile']]
            );
        }

        return $this->contextInjector->injectForSystemPrompt($basePrompt, $request);
    }

    /**
     * Extrahiere Parameter aus Request
     */
    private function extractParameters(string $request, array $schema): array
    {
        // Vereinfachte Extraktion - könnte durch LLM verbessert werden
        $parameters = [];
        
        if (isset($schema['properties'])) {
            foreach ($schema['properties'] as $paramName => $paramDef) {
                // Suche nach Parameternamen in der Request
                if (str_contains(strtolower($request), strtolower($paramName))) {
                    $parameters[$paramName] = 'value_for_' . $paramName;
                }
            }
        }

        return $parameters;
    }

    /**
     * Approve eine pending Execution
     */
    public function approvePendingExecution(string $executionId, UserInterface $approver, ?string $reason = null): array
    {
        $result = $this->hitlWorkflowManager->approveExecution(
            $executionId,
            $approver,
            $reason
        );

        if (!$result) {
            return [
                'status' => 'error',
                'message' => 'Execution nicht gefunden oder bereits verarbeitet'
            ];
        }

        return [
            'status' => $result->isSuccess() ? 'success' : 'error',
            'result' => $result->getResult(),
            'error' => $result->getError(),
            'original_request' => $result->getOriginalRequest()
        ];
    }

    /**
     * Reject eine pending Execution
     */
    public function rejectPendingExecution(string $executionId, UserInterface $rejecter, string $reason): array
    {
        $success = $this->hitlWorkflowManager->rejectExecution(
            $executionId,
            $rejecter,
            $reason
        );

        return [
            'status' => $success ? 'success' : 'error',
            'message' => $success ? 'Execution abgelehnt' : 'Execution nicht gefunden'
        ];
    }

    /**
     * Hole alle pending Executions
     */
    public function getPendingExecutions(): array
    {
        $pending = $this->hitlWorkflowManager->getPendingExecutions();
        return array_map(fn($pe) => $pe->toArray(), $pending);
    }

    /**
     * Getter für Dependency Injection
     */
    private function getEntityManager()
    {
        // Wird über Property Injection gespritzt
        return $this->entityManager ?? throw new RuntimeException('EntityManager nicht verfügbar');
    }
}
