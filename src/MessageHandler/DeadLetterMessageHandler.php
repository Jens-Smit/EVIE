<?php

namespace App\MessageHandler;

use App\Message\DeadLetterMessage;
use App\Repository\AI\AgentExecutionRepository;
use App\Service\Automation\RetryService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * DeadLetterMessageHandler processes messages that have permanently failed.
 * 
 * This handler:
 * - Logs the dead letter message
 * - Notifies administrators (future implementation)
 * - Stores the message for manual inspection
 * - Can attempt recovery (future implementation)
 */
#[AsMessageHandler]
class DeadLetterMessageHandler
{
    public function __construct(
        private AgentExecutionRepository $executionRepository,
        private RetryService $retryService,
        private LoggerInterface $logger,
        private bool $debug = false
    ) {
    }

    /**
     * Handle the DeadLetterMessage.
     */
    public function __invoke(DeadLetterMessage $message): void
    {
        $this->logger->critical('Processing dead letter message', [
            'originalMessageClass' => $message->getOriginalMessageClass(),
            'originalMessageId' => $message->getOriginalMessageId(),
            'tenantId' => $message->getTenantId(),
            'userId' => $message->getUserId(),
            'error' => $message->getError(),
            'retryCount' => $message->getRetryCount(),
            'executionId' => $message->getExecutionId(),
            'correlationId' => $message->getCorrelationId(),
        ]);

        try {
            // Step 1: Log the dead letter message
            $this->logDeadLetter($message);

            // Step 2: Update execution status if applicable
            if ($message->hasExecution()) {
                $this->updateExecutionStatus($message);
            }

            // Step 3: Store the message for manual inspection
            $this->storeDeadLetter($message);

            // Step 4: Notify administrators (in production)
            if (!$this->debug) {
                $this->notifyAdministrators($message);
            }

            $this->logger->info('Dead letter message processed', [
                'originalMessageId' => $message->getOriginalMessageId(),
            ]);

        } catch (\Exception $e) {
            $this->logger->emergency('Failed to process dead letter message', [
                'originalMessageId' => $message->getOriginalMessageId(),
                'error' => $e->getMessage(),
                'previousError' => $message->getError(),
            ]);

            // Prevent infinite loops
            throw $e;
        }
    }

    /**
     * Log the dead letter message.
     */
    private function logDeadLetter(DeadLetterMessage $message): void
    {
        $context = $message->toArray();
        
        // Remove sensitive data from logs
        unset($context['metadata']); // Remove potentially sensitive metadata

        $this->logger->error('Dead letter message received', $context);
    }

    /**
     * Update the execution status to dead_letter.
     */
    private function updateExecutionStatus(DeadLetterMessage $message): void
    {
        $executionId = $message->getExecutionId();
        if ($executionId === null) {
            return;
        }

        $execution = $this->executionRepository->find($executionId);
        
        if ($execution === null) {
            $this->logger->warning('Execution not found for dead letter message', [
                'executionId' => $executionId,
                'originalMessageId' => $message->getOriginalMessageId(),
            ]);
            return;
        }

        // Update execution status
        $execution->setStatus('dead_letter');
        $execution->setError($message->getError());
        $execution->setCompletedAt(new \DateTimeImmutable());
        
        if ($execution->getStartedAt() !== null) {
            $execution->setDuration(
                $execution->getCompletedAt()->getTimestamp() - $execution->getStartedAt()->getTimestamp()
            );
        }

        // Add dead letter metadata
        $metadata = $execution->getMetadata() ?? [];
        $metadata['deadLetter'] = [
            'reason' => $message->getError(),
            'retryCount' => $message->getRetryCount(),
            'processedAt' => (new \DateTimeImmutable())->format('c'),
        ];
        $execution->setMetadata($metadata);

        $this->executionRepository->save($execution, true);

        $this->logger->info('Updated execution status to dead_letter', [
            'executionId' => $executionId,
        ]);
    }

    /**
     * Store the dead letter message for manual inspection.
     * 
     * In a real implementation, this would store the message in a dedicated
     * dead letter queue table for later inspection and potential recovery.
     */
    private function storeDeadLetter(DeadLetterMessage $message): void
    {
        // In a real implementation, you would:
        // 1. Store the message in a dead_letter_queue table
        // 2. Store the original message payload if available
        // 3. Store the full error stack trace
        
        // For now, we'll just log it
        $this->logger->debug('Dead letter message stored for inspection', [
            'originalMessageId' => $message->getOriginalMessageId(),
            'originalMessageClass' => $message->getOriginalMessageClass(),
        ]);
    }

    /**
     * Notify administrators about the dead letter message.
     * 
     * In a real implementation, this would send an email, Slack message,
     * or other notification to administrators.
     */
    private function notifyAdministrators(DeadLetterMessage $message): void
    {
        // In a real implementation, you would:
        // 1. Send an email notification
        // 2. Send a Slack/Teams message
        // 3. Create a support ticket
        // 4. Trigger an alert in monitoring systems
        
        // For now, we'll just log it
        $this->logger->alert('Dead letter message requires attention', [
            'originalMessageClass' => $message->getOriginalMessageClass(),
            'originalMessageId' => $message->getOriginalMessageId(),
            'tenantId' => $message->getTenantId(),
            'userId' => $message->getUserId(),
            'error' => $message->getError(),
            'retryCount' => $message->getRetryCount(),
        ]);
    }

    /**
     * Attempt to recover a dead letter message.
     * 
     * This is a future feature that would allow manual recovery
     * of failed messages after fixing the underlying issue.
     * 
     * @param DeadLetterMessage $message The dead letter message to recover
     * @return bool True if recovery was successful
     */
    public function recover(DeadLetterMessage $message): bool
    {
        // This is a placeholder for future implementation
        
        $this->logger->info('Attempting to recover dead letter message', [
            'originalMessageId' => $message->getOriginalMessageId(),
        ]);

        // In a real implementation, you would:
        // 1. Recreate the original message
        // 2. Reset the retry count
        // 3. Dispatch the message to the appropriate queue
        
        return false;
    }

    /**
     * Get statistics about dead letter messages.
     * 
     * @param string $tenantId The tenant ID to filter by
     * @return array Statistics about dead letter messages
     */
    public function getStatistics(string $tenantId): array
    {
        // In a real implementation, this would query the dead letter queue
        
        return [
            'total' => 0,
            'byTenant' => [
                $tenantId => 0,
            ],
            'byMessageClass' => [],
            'byError' => [],
        ];
    }
}
