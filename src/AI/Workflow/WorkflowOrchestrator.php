<?php

namespace App\AI\Workflow;

use App\Entity\ToolDefinition;
use App\Entity\UserProfile;
use App\AI\Rag\ContextInjector;
use App\AI\Skills\Tool\DynamicTool;
use App\AI\Skills\Tool\DynamicToolFactory;
use App\AI\Skills\Tool\DynamicToolExecutor;
use App\AI\Skills\ToolDefinitionGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * WorkflowOrchestrator - Haupt-Orchestrator mit RAG-Integration.
 *
 * P1-B: Die zuvor undefinierten Methodenaufrufe (generateFromRequest,
 * createFromDefinition, getError, injectForSystemPrompt) wurden an die
 * tatsaechlich existierenden Methoden der Zielklassen angepasst
 * (generateToolDefinition, createAndRegisterTool, getErrorMessage,
 * inject). Die Klasse ist nicht als eigener Service verdrahtet und wird
 * nur ueber BriefingManager->getActiveWorkflows() erreichbar verwendet;
 * die hier korrigierten Pfade (processRequest etc.) sind aktuell nicht
 * ueber HTTP erreichbar. Eine Konsolidierung der Orchestrierungs-
 * Schichten ist als P3-D dokumentiert.
 */
class WorkflowOrchestrator
{
    private ?UserInterface $currentUser = null;
    private ?UserProfile $currentUserProfile = null;

    public function __construct(
        private DynamicToolFactory $toolFactory,
        private DynamicToolExecutor $toolExecutor,
        private ToolDefinitionGenerator $toolDefinitionGenerator,
        private HitlWorkflowManager $hitlWorkflowManager,
        private ContextInjector $contextInjector,
        private EntityManagerInterface $entityManager,
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
            // 1. Pruefe ob ein Tool verfuegbar ist
            $tool = $this->findMatchingTool($request);
            
            if ($tool) {
                // Tool gefunden - fuehre es aus
                return $this->executeTool($tool, $request);
            }

            // 2. Kein Tool gefunden - generiere neues Tool
            return $this->generateAndExecuteTool($request);

        } catch (\Exception $e) {
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
            // Einfache Matching-Logik (koennte durch LLM verbessert werden)
            $toolName = strtolower($tool->getName());
            $requestLower = strtolower($request);
            
            if (str_contains($requestLower, $toolName)) {
                return $tool;
            }
        }

        return null;
    }

    /**
     * Fuehre ein Tool aus
     */
    private function executeTool(DynamicTool $tool, string $request): array
    {
        // Pruefe ob Tool HITL erfordert
        if ($tool->requiresHitl()) {
            // Blockiere Execution fuer HITL
            $executionId = uniqid('exec_', true);
            $pendingExecution = $this->hitlWorkflowManager->blockExecution(
                $executionId,
                $tool,
                [],
                $this->currentUser ?? throw new RuntimeException('User erforderlich fuer HITL'),
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

        // Fuehre Tool aus
        $parameters = $this->extractParameters($request, $tool->getSchema());
        $executionResult = $this->toolExecutor->execute($tool, $parameters);

        if (!$executionResult->isSuccess()) {
            // P1-B: getError() existiert nicht; getErrorMessage() ist die
            // tatsaechliche Methode auf ToolExecutionResult.
            return [
                'status' => 'error',
                'tool_name' => $tool->getName(),
                'error' => $executionResult->getErrorMessage()
            ];
        }

        return [
            'status' => 'success',
            'tool_name' => $tool->getName(),
            'result' => $executionResult->getResult()
        ];
    }

    /**
     * Generiere und fuehre ein neues Tool aus
     */
    private function generateAndExecuteTool(string $request): array
    {
        // P1-B: generateFromRequest() existiert nicht; die tatsaechliche
        // Methode ist generateToolDefinition(toolName, description, context).
        // Der Request wird als Description verwendet; ein Name wird aus dem
        // Request abgeleitet.
        $toolName = $this->deriveToolName($request);
        $definition = $this->toolDefinitionGenerator->generateToolDefinition(
            $toolName,
            $request,
            ['source' => 'workflow_orchestrator']
        );
        
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

        // Blockiere fuer HITL
        $executionId = uniqid('exec_', true);
        // P1-B: createFromDefinition() existiert nicht auf DynamicToolFactory;
        // die tatsaechliche Methode ist createAndRegisterTool(ToolDefinition).
        $tool = $this->toolFactory->createAndRegisterTool($definition);
        
        $pendingExecution = $this->hitlWorkflowManager->blockExecution(
            $executionId,
            $tool,
            [],
            $this->currentUser ?? throw new RuntimeException('User erforderlich fuer HITL'),
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
        // P1-B: injectForSystemPrompt() existiert nicht auf ContextInjector;
        // die tatsaechliche Methode ist inject(prompt, query, options).
        $contentTypes = $this->currentUserProfile
            ? ['content_types' => ['user_profile']]
            : [];

        return $this->contextInjector->inject($basePrompt, $request, $contentTypes);
    }

    /**
     * Extrahiere Parameter aus Request
     */
    private function extractParameters(string $request, array $schema): array
    {
        // Vereinfachte Extraktion - koennte durch LLM verbessert werden
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
     * Leitet einen Tool-Namen aus einem Request-Text ab (P1-B: Hilfsmethode
     * fuer generateToolDefinition, das Name + Description erwartet).
     */
    private function deriveToolName(string $request): string
    {
        $words = preg_split('/\s+/', trim($request)) ?: [];
        $words = array_slice($words, 0, 3);

        return implode('_', array_map('strtolower', $words)) ?: 'generated_tool';
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
            // P1-B: getError() existiert nicht; getErrorMessage() ist die
            // tatsaechliche Methode auf ToolExecutionResult.
            'error' => $result->getErrorMessage(),
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
     * Gibt aktive (ausstehende) Workflows fuer einen Benutzer zurueck,
     * aufbereitet als Uebersicht fuer Briefings.
     *
     * @return list<array{
     *     id: string,
     *     task: string,
     *     status: string,
     *     progress: int,
     *     estimated_duration: string,
     *     risk_level: string,
     *     created_at: string
     * }>
     */
    public function getActiveWorkflows(string $userIdentifier = null): array
    {
        $pending = $this->hitlWorkflowManager->getPendingExecutions();

        $workflows = array_map(function ($pe) use ($userIdentifier) {
            $data = $pe->toArray();
            // Nur Workflows des angefragten Benutzers beruecksichtigen, falls
            // ein UserIdentifier uebergeben wurde.
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

        // Herausgefilterte (null) Eintraege entfernen.
        return array_values(array_filter($workflows, fn ($w) => $w !== null));
    }
}
