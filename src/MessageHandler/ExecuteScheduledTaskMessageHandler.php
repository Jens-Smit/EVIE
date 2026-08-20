<?php

namespace App\MessageHandler;

use App\Entity\Automation\ScheduledTask;
use App\Message\ExecuteAgentMessage;
use App\Message\ExecuteScheduledTaskMessage;
use App\Message\DeadLetterMessage;
use App\Repository\Automation\ScheduledTaskRepository;
use App\Service\Automation\IdempotencyService;
use App\Service\Automation\RetryService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * ExecuteScheduledTaskMessageHandler processes scheduled task execution requests.
 * 
 * This handler:
 * - Validates tenant isolation
 * - Checks for idempotency
 * - Updates task status
 * - Dispatches ExecuteAgentMessage for actual execution
 * - Handles retries and errors
 */
#[AsMessageHandler]
class ExecuteScheduledTaskMessageHandler
{
    public function __construct(
        private ScheduledTaskRepository $taskRepository,
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $messageBus,
        private IdempotencyService $idempotencyService,
        private RetryService $retryService,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Handle the ExecuteScheduledTaskMessage.
     */
    public function __invoke(ExecuteScheduledTaskMessage $message): void
    {
        $taskId = $message->getTaskId();
        $userId = $message->getUserId();
        $tenantId = $message->getTenantId();
        $taskName = $message->getTaskName();

        $this->logger->info('Processing scheduled task execution', [
            'taskId' => $taskId,
            'taskName' => $taskName,
            'userId' => $userId,
            'tenantId' => $tenantId,
            'retryCount' => $message->getRetryCount(),
        ]);

        try {
            // Step 1: Find the task
            $task = $this->taskRepository->findOneByIdAndTenant($taskId, $tenantId);
            
            if ($task === null) {
                throw new \RuntimeException("Task not found: {$taskId} for tenant: {$tenantId}");
            }

            // Step 2: Validate tenant isolation
            $this->validateTenantIsolation($task, $userId, $tenantId);

            // Step 3: Check for idempotency
            if ($this->idempotencyService->isDuplicate($message->getIdempotencyKey())) {
                $this->logger->info('Duplicate scheduled task execution detected, skipping', [
                    'idempotencyKey' => $message->getIdempotencyKey(),
                ]);
                return;
            }

            // Step 4: Check if task is locked
            if ($task->isLocked() && !$message->getMetadata()['force'] ?? false) {
                $this->logger->warning('Task is locked by another worker, skipping', [
                    'taskId' => $taskId,
                ]);
                return;
            }

            // Step 5: Lock the task
            $this->taskRepository->save($task->lock($message->getIdempotencyKey()), true);

            // Step 6: Update task status
            $task->setStatus('running');
            $task->setLastRunAt(new \DateTimeImmutable());
            $task->incrementRunCount();
            $this->entityManager->flush();

            // Step 7: Dispatch ExecuteAgentMessage for actual execution
            $agentMessage = new ExecuteAgentMessage(
                executionId: $message->getIdempotencyKey(),
                userId: $userId,
                tenantId: $tenantId,
                agentName: $message->getAction(),
                conversationId: null,
                parentExecutionId: null,
                idempotencyKey: Ulid::generate(),
                correlationId: $message->getCorrelationId(),
                parameters: $message->getParameters(),
                metadata: [
                    'scheduledTaskId' => $taskId,
                    'scheduledTaskName' => $taskName,
                    'schedule' => $message->getSchedule(),
                ],
                scheduledTaskId: $taskId
            );

            $this->messageBus->dispatch($agentMessage);

            // Step 8: Update task status and next run time
            $task->setStatus('completed');
            $task->setLastStatus('success');
            $task->setLastError(null);
            $task->setNextRunAt($this->calculateNextRunAt($task));
            $this->entityManager->flush();

            $this->logger->info('Scheduled task execution queued', [
                'taskId' => $taskId,
                'executionId' => $agentMessage->getExecutionId(),
                'nextRunAt' => $task->getNextRunAt()?->format('c'),
            ]);

        } catch (\Exception $e) {
            $this->handleExecutionError($message, $e);
        }
    }

    /**
     * Validate tenant isolation for the task.
     * 
     * @param ScheduledTask $task The task
     * @param string $userId The user ID
     * @param string $tenantId The tenant ID
     * @throws \RuntimeException If tenant isolation is violated
     */
    private function validateTenantIsolation(ScheduledTask $task, string $userId, string $tenantId): void
    {
        if (!$task->belongsToTenant($tenantId)) {
            throw new \RuntimeException(
                "Tenant isolation violation: Task {$task->getId()} does not belong to tenant {$tenantId}"
            );
        }

        if (!$task->belongsToUser($userId)) {
            throw new \RuntimeException(
                "User isolation violation: Task {$task->getId()} does not belong to user {$userId}"
            );
        }
    }

    /**
     * Calculate the next run time for a task.
     * 
     * @param ScheduledTask $task The task
     * @return \DateTimeImmutable The next run time
     */
    private function calculateNextRunAt(ScheduledTask $task): \DateTimeImmutable
    {
        return $task->calculateNextRunAt();
    }

    /**
     * Handle execution errors.
     * 
     * @param ExecuteScheduledTaskMessage $message The message
     * @param \Exception $e The exception
     */
    private function handleExecutionError(ExecuteScheduledTaskMessage $message, \Exception $e): void
    {
        $taskId = $message->getTaskId();
        $retryCount = $message->getRetryCount();

        $this->logger->error('Scheduled task execution failed', [
            'taskId' => $taskId,
            'taskName' => $message->getTaskName(),
            'error' => $e->getMessage(),
            'retryCount' => $retryCount,
        ]);

        try {
            // Find the task
            $task = $this->taskRepository->find($taskId);
            
            if ($task === null) {
                $this->logger->error('Task not found during error handling', [
                    'taskId' => $taskId,
                ]);
                return;
            }

            // Update task with error
            $task->setStatus('failed');
            $task->setLastStatus('error');
            $task->setLastError($e->getMessage());
            $task->incrementFailureCount();
            $task->setNextRunAt($this->calculateNextRunAt($task));
            $this->entityManager->flush();

            // Check if we should retry
            if ($this->retryService->shouldRetryByCount($retryCount)) {
                $this->logger->info('Retrying scheduled task execution', [
                    'taskId' => $taskId,
                    'retryCount' => $retryCount + 1,
                ]);

                // Create retry message
                $retryMessage = $message->createRetryMessage();
                
                // Dispatch with delay
                $delay = $this->retryService->calculateDelay($retryCount);
                $this->messageBus->dispatch($retryMessage, [
                    'delay' => $delay,
                ]);
            } else {
                // Permanent failure - send to dead letter
                $this->logger->error('Permanent scheduled task failure', [
                    'taskId' => $taskId,
                    'error' => $e->getMessage(),
                ]);

                $this->sendToDeadLetter($message, $e);
            }

        } catch (\Exception $innerException) {
            $this->logger->emergency('Failed to handle scheduled task error', [
                'taskId' => $taskId,
                'error' => $e->getMessage(),
                'innerError' => $innerException->getMessage(),
            ]);
        }
    }

    /**
     * Send a message to the dead letter queue.
     * 
     * @param ExecuteScheduledTaskMessage $message The failed message
     * @param \Exception $e The exception
     */
    private function sendToDeadLetter(ExecuteScheduledTaskMessage $message, \Exception $e): void
    {
        $deadLetterMessage = new DeadLetterMessage(
            originalMessageClass: ExecuteScheduledTaskMessage::class,
            originalMessageId: $message->getTaskId(),
            tenantId: $message->getTenantId(),
            userId: $message->getUserId(),
            error: $e->getMessage(),
            retryCount: $message->getRetryCount(),
            executionId: null,
            correlationId: $message->getCorrelationId(),
            metadata: [
                'taskName' => $message->getTaskName(),
                'action' => $message->getAction(),
                'schedule' => $message->getSchedule(),
            ]
        );

        $this->messageBus->dispatch($deadLetterMessage);
    }
}
