<?php

namespace App\MessageHandler;

use App\Entity\AI\AgentExecution;
use App\Entity\AI\Conversation;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\User;
use App\Message\ExecuteAgentMessage;
use App\Repository\AI\AgentExecutionRepository;
use App\Repository\AI\ConversationRepository;
use App\Repository\Tenant\TenantRepository;
use App\Repository\Tenant\UserRepository;
use App\Service\Security\SecretManager;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * ExecuteAgentMessageHandler processes async agent execution requests.
 * 
 * This handler:
 * - Validates tenant isolation
 * - Checks for idempotency
 * - Creates AgentExecution entity
 * - Executes the agent logic
 * - Handles retries and errors
 * - Updates execution status
 * 
 * @see ExecuteAgentMessage
 */
#[AsMessageHandler]
class ExecuteAgentMessageHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AgentExecutionRepository $executionRepository,
        private UserRepository $userRepository,
        private TenantRepository $tenantRepository,
        private ConversationRepository $conversationRepository,
        private SecretManager $secretManager,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
        private array $agentConfig = []
    ) {
    }

    /**
     * Handle the ExecuteAgentMessage.
     * 
     * @throws \RuntimeException If execution fails permanently
     */
    public function __invoke(ExecuteAgentMessage $message): void
    {
        $executionId = $message->getExecutionId();
        $userId = $message->getUserId();
        $tenantId = $message->getTenantId();
        $agentName = $message->getAgentName();

        $this->logger->info('Processing agent execution', [
            'executionId' => $executionId,
            'agentName' => $agentName,
            'userId' => $userId,
            'tenantId' => $tenantId,
            'retryCount' => $message->getRetryCount(),
        ]);

        try {
            // Step 1: Validate tenant isolation
            $this->validateTenantIsolation($userId, $tenantId);

            // Step 2: Check for idempotency
            if ($this->isDuplicateExecution($message->getIdempotencyKey())) {
                $this->logger->info('Duplicate execution detected, skipping', [
                    'idempotencyKey' => $message->getIdempotencyKey(),
                ]);
                return;
            }

            // Step 3: Find or create execution entity
            $execution = $this->executionRepository->find($executionId);
            
            if ($execution === null) {
                $execution = $this->createExecutionEntity($message);
                $this->entityManager->persist($execution);
                $this->entityManager->flush();
                
                $this->logger->debug('Created new AgentExecution entity', [
                    'executionId' => $executionId,
                ]);
            } else {
                // Update existing execution (e.g., for retries)
                $execution->setRetryCount($message->getRetryCount());
                $execution->setUpdatedAt(new \DateTimeImmutable());
                $this->entityManager->flush();
            }

            // Step 4: Mark as running
            $execution->setStatus('running');
            $execution->setStartedAt(new \DateTimeImmutable());
            $this->entityManager->flush();

            // Step 5: Execute the agent
            $this->executeAgent($execution, $message);

            // Step 6: Mark as completed
            $execution->setStatus('completed');
            $execution->setCompletedAt(new \DateTimeImmutable());
            $execution->setDuration(
                $execution->getCompletedAt()->getTimestamp() - $execution->getStartedAt()->getTimestamp()
            );
            $this->entityManager->flush();

            $this->logger->info('Agent execution completed', [
                'executionId' => $executionId,
                'duration' => $execution->getDuration(),
            ]);

        } catch (\Exception $e) {
            $this->handleExecutionError($message, $e);
        }
    }

    /**
     * Validate tenant isolation.
     * 
     * @throws \RuntimeException If tenant isolation is violated
     */
    private function validateTenantIsolation(string $userId, string $tenantId): void
    {
        $user = $this->userRepository->find($userId);
        
        if ($user === null) {
            throw new \RuntimeException("User not found: {$userId}");
        }

        if (!$user->belongsToTenant($tenantId)) {
            throw new \RuntimeException(
                "Tenant isolation violation: User {$userId} does not belong to tenant {$tenantId}"
            );
        }
    }

    /**
     * Check if this execution has already been processed (idempotency).
     */
    private function isDuplicateExecution(string $idempotencyKey): bool
    {
        $existingExecution = $this->executionRepository->findByIdempotencyKey($idempotencyKey);
        
        if ($existingExecution === null) {
            return false;
        }

        // If execution is already completed, it's a duplicate
        if ($existingExecution->isCompleted()) {
            return true;
        }

        // If execution is running, we might want to wait or fail
        // For now, we'll consider it a duplicate to prevent concurrent execution
        return true;
    }

    /**
     * Create an AgentExecution entity from the message.
     */
    private function createExecutionEntity(ExecuteAgentMessage $message): AgentExecution
    {
        $execution = new AgentExecution();
        
        // Set basic properties
        $execution->setId($message->getExecutionId());
        $execution->setAgent($message->getAgentName());
        $execution->setStatus('created');
        $execution->setIdempotencyKey($message->getIdempotencyKey());
        $execution->setCorrelationId($message->getCorrelationId());
        $execution->setRetryCount($message->getRetryCount());
        $execution->setMetadata($message->getMetadata());

        // Set user and tenant
        $user = $this->userRepository->find($message->getUserId());
        $tenant = $this->tenantRepository->find($message->getTenantId());
        
        if ($user === null) {
            throw new \RuntimeException("User not found: {$message->getUserId()}");
        }
        
        if ($tenant === null) {
            throw new \RuntimeException("Tenant not found: {$message->getTenantId()}");
        }

        $execution->setUser($user);
        $execution->setTenant($tenant);

        // Set conversation if provided
        if ($message->getConversationId() !== null) {
            $conversation = $this->conversationRepository->find($message->getConversationId());
            if ($conversation !== null) {
                $execution->setConversation($conversation);
            }
        }

        // Set parent execution if provided
        if ($message->getParentExecutionId() !== null) {
            $parentExecution = $this->executionRepository->find($message->getParentExecutionId());
            if ($parentExecution !== null) {
                $execution->setParentExecution($parentExecution);
            }
        }

        return $execution;
    }

    /**
     * Execute the agent logic.
     * 
     * This is a placeholder for the actual agent execution logic.
     * In a real implementation, this would call the appropriate agent service.
     * 
     * @throws \RuntimeException If agent execution fails
     */
    private function executeAgent(AgentExecution $execution, ExecuteAgentMessage $message): void
    {
        $agentName = $execution->getAgent();
        $parameters = $message->getParameters();

        $this->logger->debug('Executing agent', [
            'executionId' => $execution->getId(),
            'agentName' => $agentName,
            'parameters' => array_keys($parameters), // Don't log sensitive data
        ]);

        // Check if agent is configured
        if (!isset($this->agentConfig[$agentName])) {
            throw new \RuntimeException("Agent not configured: {$agentName}");
        }

        $agentConfig = $this->agentConfig[$agentName];

        // Execute the agent based on its type
        switch ($agentConfig['type'] ?? 'default') {
            case 'llm':
                $this->executeLLMAgent($execution, $message);
                break;
            
            case 'tool':
                $this->executeToolAgent($execution, $message);
                break;
            
            case 'workflow':
                $this->executeWorkflowAgent($execution, $message);
                break;
            
            default:
                $this->executeDefaultAgent($execution, $message);
                break;
        }

        // Store results in execution
        $execution->setResults([
            'status' => 'success',
            'agent' => $agentName,
            'executedAt' => (new \DateTimeImmutable())->format('c'),
            'parameters' => array_keys($parameters), // Don't store sensitive data
        ]);
    }

    /**
     * Execute an LLM-based agent.
     */
    private function executeLLMAgent(AgentExecution $execution, ExecuteAgentMessage $message): void
    {
        $agentName = $execution->getAgent();
        $parameters = $message->getParameters();

        // This is a placeholder for actual LLM execution
        // In a real implementation, this would call the LLM service
        
        $this->logger->debug('Executing LLM agent', [
            'executionId' => $execution->getId(),
            'agentName' => $agentName,
        ]);

        // Simulate LLM processing
        // In production, this would call the LLM provider
        $prompt = $parameters['prompt'] ?? '';
        $maxTokens = $parameters['maxTokens'] ?? 1024;
        
        // Here you would:
        // 1. Get the LLM configuration for the user/tenant
        // 2. Get the secret (API key) from SecretManager
        // 3. Call the LLM provider
        // 4. Process the response
        
        // For now, we'll just simulate a response
        $execution->setResults([
            'type' => 'llm',
            'prompt' => substr($prompt, 0, 100) . '...', // Don't store full prompt
            'maxTokens' => $maxTokens,
            'response' => '[Simulated LLM response]',
        ]);
    }

    /**
     * Execute a tool-based agent.
     */
    private function executeToolAgent(AgentExecution $execution, ExecuteAgentMessage $message): void
    {
        $agentName = $execution->getAgent();
        $parameters = $message->getParameters();

        $this->logger->debug('Executing tool agent', [
            'executionId' => $execution->getId(),
            'agentName' => $agentName,
        ]);

        // This is a placeholder for actual tool execution
        // In a real implementation, this would call the tool service
        
        $toolName = $parameters['tool'] ?? $agentName;
        $toolParameters = $parameters['parameters'] ?? [];

        // Here you would:
        // 1. Validate the tool exists
        // 2. Validate permissions
        // 3. Execute the tool
        // 4. Process the result
        
        $execution->setResults([
            'type' => 'tool',
            'tool' => $toolName,
            'parameters' => array_keys($toolParameters),
            'result' => '[Simulated tool result]',
        ]);
    }

    /**
     * Execute a workflow agent (multiple steps).
     */
    private function executeWorkflowAgent(AgentExecution $execution, ExecuteAgentMessage $message): void
    {
        $agentName = $execution->getAgent();
        $parameters = $message->getParameters();

        $this->logger->debug('Executing workflow agent', [
            'executionId' => $execution->getId(),
            'agentName' => $agentName,
        ]);

        // This is a placeholder for workflow execution
        // In a real implementation, this would execute multiple steps
        
        $steps = $parameters['steps'] ?? [];
        $stepResults = [];

        foreach ($steps as $step) {
            // For each step, create a child execution
            $childExecution = new AgentExecution();
            $childExecution->setId(\Symfony\Component\Uid\Ulid::generate());
            $childExecution->setAgent($step['agent'] ?? 'unknown');
            $childExecution->setUser($execution->getUser());
            $childExecution->setTenant($execution->getTenant());
            $childExecution->setConversation($execution->getConversation());
            $childExecution->setParentExecution($execution);
            $childExecution->setStatus('created');
            $childExecution->setIdempotencyKey(\Symfony\Component\Uid\Ulid::generate());
            $childExecution->setCorrelationId($execution->getCorrelationId());

            $execution->addChildExecution($childExecution);
            $this->entityManager->persist($childExecution);

            // Dispatch the child execution as a new message
            $childMessage = new ExecuteAgentMessage(
                executionId: $childExecution->getId(),
                userId: $execution->getUserId(),
                tenantId: $execution->getTenantId(),
                agentName: $step['agent'] ?? 'unknown',
                conversationId: $execution->getConversationId(),
                parentExecutionId: $execution->getId(),
                idempotencyKey: $childExecution->getIdempotencyKey(),
                correlationId: $execution->getCorrelationId(),
                parameters: $step['parameters'] ?? [],
                metadata: $step['metadata'] ?? []
            );

            $this->messageBus->dispatch($childMessage);

            $stepResults[] = [
                'agent' => $step['agent'] ?? 'unknown',
                'executionId' => $childExecution->getId(),
                'status' => 'queued',
            ];
        }

        $this->entityManager->flush();

        $execution->setResults([
            'type' => 'workflow',
            'steps' => $stepResults,
            'childExecutions' => count($stepResults),
        ]);
    }

    /**
     * Execute a default agent.
     */
    private function executeDefaultAgent(AgentExecution $execution, ExecuteAgentMessage $message): void
    {
        $agentName = $execution->getAgent();

        $this->logger->debug('Executing default agent', [
            'executionId' => $execution->getId(),
            'agentName' => $agentName,
        ]);

        // Default behavior: just mark as executed
        $execution->setResults([
            'type' => 'default',
            'agent' => $agentName,
            'message' => 'Agent executed successfully',
        ]);
    }

    /**
     * Handle execution errors.
     * 
     * @throws \RuntimeException If error is permanent
     */
    private function handleExecutionError(ExecuteAgentMessage $message, \Exception $e): void
    {
        $executionId = $message->getExecutionId();
        $retryCount = $message->getRetryCount();

        $this->logger->error('Agent execution failed', [
            'executionId' => $executionId,
            'agentName' => $message->getAgentName(),
            'error' => $e->getMessage(),
            'retryCount' => $retryCount,
            'exception' => get_class($e),
        ]);

        // Find the execution entity
        $execution = $this->executionRepository->find($executionId);

        if ($execution === null) {
            // If execution doesn't exist, log and rethrow
            $this->logger->error('Execution entity not found during error handling', [
                'executionId' => $executionId,
            ]);
            throw $e;
        }

        // Update execution with error
        $execution->setError($e->getMessage());
        $execution->setRetryCount($retryCount);
        $execution->setStatus('failed');
        $execution->setCompletedAt(new \DateTimeImmutable());
        
        if ($execution->getStartedAt() !== null) {
            $execution->setDuration(
                $execution->getCompletedAt()->getTimestamp() - $execution->getStartedAt()->getTimestamp()
            );
        }
        
        $this->entityManager->flush();

        // Check if we should retry
        if ($this->shouldRetry($message, $e)) {
            $this->logger->info('Retrying agent execution', [
                'executionId' => $executionId,
                'retryCount' => $retryCount + 1,
            ]);

            // Create retry message
            $retryMessage = $message->createRetryMessage();
            
            // Dispatch with delay (exponential backoff)
            $delay = $this->calculateRetryDelay($retryCount);
            $this->messageBus->dispatch($retryMessage, [
                'delay' => $delay,
            ]);
        } else {
            // Permanent failure - move to dead letter queue
            $this->logger->error('Permanent agent execution failure', [
                'executionId' => $executionId,
                'error' => $e->getMessage(),
            ]);

            // Here you would typically send to a dead letter queue
            // For now, we'll just log it
            $this->handleDeadLetter($message, $e);
        }
    }

    /**
     * Determine if we should retry the execution.
     */
    private function shouldRetry(ExecuteAgentMessage $message, \Exception $e): bool
    {
        $retryCount = $message->getRetryCount();
        
        // Max retries (configurable)
        $maxRetries = 3;
        
        if ($retryCount >= $maxRetries) {
            return false;
        }

        // Don't retry for certain errors
        $nonRetryableErrors = [
            'Tenant isolation violation',
            'User not found',
            'Tenant not found',
            'Agent not configured',
            'Permission denied',
        ];

        foreach ($nonRetryableErrors as $error) {
            if (str_contains($e->getMessage(), $error)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Calculate retry delay using exponential backoff.
     */
    private function calculateRetryDelay(int $retryCount): int
    {
        // Exponential backoff: 1s, 2s, 4s, 8s, etc.
        $baseDelay = 1000; // 1 second in milliseconds
        $delay = $baseDelay * (2 ** $retryCount);
        
        // Max delay: 60 seconds
        return min($delay, 60000);
    }

    /**
     * Handle dead letter queue for permanent failures.
     */
    private function handleDeadLetter(ExecuteAgentMessage $message, \Exception $e): void
    {
        // In a real implementation, you would:
        // 1. Store the failed message in a dead letter queue
        // 2. Notify administrators
        // 3. Log for analysis
        
        $this->logger->critical('Message moved to dead letter queue', [
            'executionId' => $message->getExecutionId(),
            'agentName' => $message->getAgentName(),
            'userId' => $message->getUserId(),
            'tenantId' => $message->getTenantId(),
            'error' => $e->getMessage(),
            'retryCount' => $message->getRetryCount(),
        ]);

        // Here you could dispatch to a dead letter queue
        // $this->messageBus->dispatch($message, [
        //     'queue' => 'dead_letter',
        // ]);
    }
}
