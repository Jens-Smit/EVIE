<?php

namespace App\Service\Automation;

use App\Entity\AI\AgentExecution;
use App\Repository\AI\AgentExecutionRepository;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;

/**
 * RetryService handles retry logic for failed operations.
 * 
 * Features:
 * - Exponential backoff calculation
 * - Maximum retry configuration
 * - Retryable vs non-retryable error classification
 * - Retry policy management
 * - Dead letter queue support
 */
class RetryService
{
    private const int DEFAULT_MAX_RETRIES = 3;
    private const int DEFAULT_BASE_DELAY = 1000; // 1 second in milliseconds
    private const int DEFAULT_MAX_DELAY = 60000; // 60 seconds in milliseconds
    private const array DEFAULT_NON_RETRYABLE_ERRORS = [
        'Tenant isolation violation',
        'User not found',
        'Tenant not found',
        'Agent not configured',
        'Permission denied',
        'Invalid configuration',
        'Access denied',
    ];

    public function __construct(
        private AgentExecutionRepository $executionRepository,
        private EntityManagerInterface $entityManager,
        private array $config = []
    ) {
        $this->config = array_merge([
            'max_retries' => self::DEFAULT_MAX_RETRIES,
            'base_delay' => self::DEFAULT_BASE_DELAY,
            'max_delay' => self::DEFAULT_MAX_DELAY,
            'non_retryable_errors' => self::DEFAULT_NON_RETRYABLE_ERRORS,
        ], $config);
    }

    /**
     * Check if an execution should be retried.
     * 
     * @param AgentExecution $execution The execution to check
     * @return bool True if the execution should be retried
     */
    public function shouldRetry(AgentExecution $execution): bool
    {
        $retryCount = $execution->getRetryCount();
        
        // Check max retries
        if ($retryCount >= $this->config['max_retries']) {
            return false;
        }

        // Check if error is retryable
        $error = $execution->getError();
        if ($error !== null && $this->isNonRetryableError($error)) {
            return false;
        }

        return true;
    }

    /**
     * Check if an error is non-retryable.
     * 
     * @param string $error The error message
     * @return bool True if the error should not be retried
     */
    public function isNonRetryableError(string $error): bool
    {
        foreach ($this->config['non_retryable_errors'] as $nonRetryableError) {
            if (str_contains($error, $nonRetryableError)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Calculate the delay for the next retry using exponential backoff.
     * 
     * @param int $retryCount The current retry count (0-based)
     * @return int Delay in milliseconds
     */
    public function calculateDelay(int $retryCount): int
    {
        // Exponential backoff: baseDelay * 2^retryCount
        $delay = $this->config['base_delay'] * (2 ** $retryCount);
        
        // Apply jitter (random variation to prevent thundering herd)
        $jitter = random_int(0, (int)($delay * 0.1)); // 10% jitter
        $delay += $jitter;
        
        // Cap at max delay
        return min($delay, $this->config['max_delay']);
    }

    /**
     * Get the next retry delay for an execution.
     * 
     * @param AgentExecution $execution The execution
     * @return int Delay in milliseconds
     */
    public function getNextRetryDelay(AgentExecution $execution): int
    {
        return $this->calculateDelay($execution->getRetryCount());
    }

    /**
     * Increment the retry count for an execution.
     * 
     * @param AgentExecution $execution The execution to update
     * @return AgentExecution The updated execution
     */
    public function incrementRetryCount(AgentExecution $execution): AgentExecution
    {
        $execution->incrementRetryCount();
        $this->entityManager->persist($execution);
        $this->entityManager->flush();
        
        return $execution;
    }

    /**
     * Reset the retry count for an execution.
     * 
     * @param AgentExecution $execution The execution to update
     * @return AgentExecution The updated execution
     */
    public function resetRetryCount(AgentExecution $execution): AgentExecution
    {
        $execution->setRetryCount(0);
        $this->entityManager->persist($execution);
        $this->entityManager->flush();
        
        return $execution;
    }

    /**
     * Get the retry policy for an execution.
     * 
     * @param AgentExecution $execution The execution
     * @return array Retry policy information
     */
    public function getRetryPolicy(AgentExecution $execution): array
    {
        $retryCount = $execution->getRetryCount();
        $maxRetries = $this->config['max_retries'];
        
        return [
            'retryCount' => $retryCount,
            'maxRetries' => $maxRetries,
            'canRetry' => $retryCount < $maxRetries,
            'nextDelay' => $this->calculateDelay($retryCount),
            'nextDelayFormatted' => $this->formatDelay($this->calculateDelay($retryCount)),
        ];
    }

    /**
     * Format a delay in milliseconds to a human-readable string.
     * 
     * @param int $milliseconds Delay in milliseconds
     * @return string Human-readable delay
     */
    public function formatDelay(int $milliseconds): string
    {
        if ($milliseconds < 1000) {
            return $milliseconds . 'ms';
        }
        
        $seconds = $milliseconds / 1000;
        
        if ($seconds < 60) {
            return round($seconds, 1) . 's';
        }
        
        $minutes = $seconds / 60;
        
        if ($minutes < 60) {
            return round($minutes, 1) . 'm';
        }
        
        $hours = $minutes / 60;
        return round($hours, 1) . 'h';
    }

    /**
     * Move an execution to the dead letter queue.
     * 
     * @param AgentExecution $execution The execution to move
     * @param string $reason The reason for moving to dead letter queue
     * @return AgentExecution The updated execution
     */
    public function moveToDeadLetter(AgentExecution $execution, string $reason): AgentExecution
    {
        $execution->setStatus('dead_letter');
        $execution->setError($reason);
        $execution->setCompletedAt(new \DateTimeImmutable());
        
        if ($execution->getStartedAt() !== null) {
            $execution->setDuration(
                $execution->getCompletedAt()->getTimestamp() - $execution->getStartedAt()->getTimestamp()
            );
        }
        
        $this->entityManager->persist($execution);
        $this->entityManager->flush();
        
        return $execution;
    }

    /**
     * Get all executions that can be retried.
     * 
     * @param string $tenantId The tenant ID to filter by
     * @return AgentExecution[] Array of retryable executions
     */
    public function getRetryableExecutions(string $tenantId): array
    {
        return $this->executionRepository->findRetryable($tenantId, $this->config['max_retries']);
    }

    /**
     * Get all executions in the dead letter queue.
     * 
     * @param string $tenantId The tenant ID to filter by
     * @return AgentExecution[] Array of dead letter executions
     */
    public function getDeadLetterExecutions(string $tenantId): array
    {
        return $this->executionRepository->createQueryBuilder('e')
            ->andWhere('e.tenant = :tenantId')
            ->andWhere('e.status = :status')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('status', 'dead_letter')
            ->orderBy('e.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Retry an execution.
     * 
     * @param AgentExecution $execution The execution to retry
     * @return AgentExecution The updated execution
     * @throws RuntimeException If execution cannot be retried
     */
    public function retryExecution(AgentExecution $execution): AgentExecution
    {
        if (!$this->shouldRetry($execution)) {
            throw new RuntimeException(
                "Cannot retry execution: " . $execution->getId()
            );
        }

        // Reset error and increment retry count
        $execution->setError(null);
        $execution->setStatus('queued');
        $this->incrementRetryCount($execution);

        return $execution;
    }

    /**
     * Get retry statistics for a tenant.
     * 
     * @param string $tenantId The tenant ID
     * @return array Retry statistics
     */
    public function getRetryStatistics(string $tenantId): array
    {
        $conn = $this->entityManager->getConnection();
        
        $sql = 'SELECT 
            COUNT(*) as total,
            COUNT(CASE WHEN retry_count > 0 THEN 1 END) as retried,
            COUNT(CASE WHEN status = \'failed\' AND retry_count >= :maxRetries THEN 1 END) as dead_letter,
            AVG(retry_count) as avg_retries,
            MAX(retry_count) as max_retries
            FROM agent_execution 
            WHERE tenant_id = :tenantId';
        
        $stmt = $conn->prepare($sql);
        $stmt->executeQuery([
            'tenantId' => $tenantId,
            'maxRetries' => $this->config['max_retries'],
        ]);
        
        return $stmt->fetchAssociative();
    }

    /**
     * Configure the retry service.
     * 
     * @param array $config Configuration options
     * @return self
     */
    public function configure(array $config): self
    {
        $this->config = array_merge($this->config, $config);
        return $this;
    }

    /**
     * Get the current configuration.
     * 
     * @return array The current configuration
     */
    public function getConfiguration(): array
    {
        return $this->config;
    }
}
