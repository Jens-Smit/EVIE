<?php

namespace App\Service\Automation;

use App\Entity\AI\AgentExecution;
use App\Repository\AI\AgentExecutionRepository;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;

/**
 * IdempotencyService ensures that operations are not executed multiple times.
 * 
 * This service provides:
 * - Idempotency key generation
 * - Duplicate execution detection
 * - Idempotency key storage and validation
 * - Support for distributed systems
 * 
 * @see https://en.wikipedia.org/wiki/Idempotence
 */
class IdempotencyService
{
    private const KEY_PREFIX = 'idempotency_';
    private const TTL = 86400; // 24 hours in seconds

    public function __construct(
        private AgentExecutionRepository $executionRepository,
        private EntityManagerInterface $entityManager
    ) {
    }

    /**
     * Generate a new idempotency key.
     * 
     * @param string $scope The scope/namespace for the key
     * @return string A unique idempotency key
     */
    public function generateKey(string $scope = 'default'): string
    {
        return self::KEY_PREFIX . $scope . '_' . \Symfony\Component\Uid\Ulid::generate();
    }

    /**
     * Check if an operation with the given idempotency key has already been executed.
     * 
     * @param string $idempotencyKey The idempotency key to check
     * @return bool True if the operation has already been executed
     */
    public function isDuplicate(string $idempotencyKey): bool
    {
        $execution = $this->executionRepository->findByIdempotencyKey($idempotencyKey);
        
        if ($execution === null) {
            return false;
        }

        // Check if execution is completed
        return $execution->isCompleted();
    }

    /**
     * Check if an operation with the given idempotency key is currently running.
     * 
     * @param string $idempotencyKey The idempotency key to check
     * @return bool True if the operation is currently running
     */
    public function isRunning(string $idempotencyKey): bool
    {
        $execution = $this->executionRepository->findByIdempotencyKey($idempotencyKey);
        
        if ($execution === null) {
            return false;
        }

        return $execution->isRunning();
    }

    /**
     * Get the execution for a given idempotency key.
     * 
     * @param string $idempotencyKey The idempotency key
     * @return AgentExecution|null The execution entity or null if not found
     */
    public function getExecution(string $idempotencyKey): ?AgentExecution
    {
        return $this->executionRepository->findByIdempotencyKey($idempotencyKey);
    }

    /**
     * Mark an operation as started with the given idempotency key.
     * 
     * @param string $idempotencyKey The idempotency key
     * @param AgentExecution $execution The execution entity
     * @return AgentExecution The updated execution entity
     */
    public function markAsStarted(string $idempotencyKey, AgentExecution $execution): AgentExecution
    {
        $execution->setIdempotencyKey($idempotencyKey);
        $execution->setStatus('running');
        $execution->setStartedAt(new \DateTimeImmutable());
        
        $this->entityManager->persist($execution);
        $this->entityManager->flush();

        return $execution;
    }

    /**
     * Mark an operation as completed with the given idempotency key.
     * 
     * @param string $idempotencyKey The idempotency key
     * @param AgentExecution $execution The execution entity
     * @param array $results The results of the operation
     * @return AgentExecution The updated execution entity
     */
    public function markAsCompleted(string $idempotencyKey, AgentExecution $execution, array $results = []): AgentExecution
    {
        $execution->setIdempotencyKey($idempotencyKey);
        $execution->setStatus('completed');
        $execution->setCompletedAt(new \DateTimeImmutable());
        $execution->setResults($results);
        
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
     * Mark an operation as failed with the given idempotency key.
     * 
     * @param string $idempotencyKey The idempotency key
     * @param AgentExecution $execution The execution entity
     * @param string $error The error message
     * @return AgentExecution The updated execution entity
     */
    public function markAsFailed(string $idempotencyKey, AgentExecution $execution, string $error): AgentExecution
    {
        $execution->setIdempotencyKey($idempotencyKey);
        $execution->setStatus('failed');
        $execution->setCompletedAt(new \DateTimeImmutable());
        $execution->setError($error);
        
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
     * Create an idempotency key for a specific operation.
     * 
     * @param string $operation The operation name/type
     * @param string $userId The user ID
     * @param array $parameters The operation parameters (used for key generation)
     * @return string A unique idempotency key for this operation
     */
    public function createOperationKey(string $operation, string $userId, array $parameters = []): string
    {
        // Create a hash of the parameters to include in the key
        $paramHash = md5(json_encode($parameters));
        
        return $this->generateKey($operation . '_' . $userId . '_' . $paramHash);
    }

    /**
     * Validate that an operation can be executed (not duplicate, not running).
     * 
     * @param string $idempotencyKey The idempotency key
     * @return bool True if the operation can be executed
     * @throws RuntimeException If the operation cannot be executed
     */
    public function validateExecution(string $idempotencyKey): bool
    {
        if ($this->isDuplicate($idempotencyKey)) {
            throw new RuntimeException(
                "Duplicate operation detected for idempotency key: {$idempotencyKey}"
            );
        }

        if ($this->isRunning($idempotencyKey)) {
            throw new RuntimeException(
                "Operation is already running for idempotency key: {$idempotencyKey}"
            );
        }

        return true;
    }

    /**
     * Clean up old idempotency keys.
     * 
     * @param int $ttl Time to live in seconds (default: 24 hours)
     * @return int Number of keys cleaned up
     */
    public function cleanupOldKeys(int $ttl = self::TTL): int
    {
        // Find executions older than TTL
        $oldExecutions = $this->executionRepository->createQueryBuilder('e')
            ->where('e.completedAt IS NOT NULL')
            ->andWhere('e.completedAt < :cutoff')
            ->setParameter('cutoff', (new \DateTimeImmutable())->modify("-{$ttl} seconds"))
            ->getQuery()
            ->getResult();

        $count = 0;
        foreach ($oldExecutions as $execution) {
            $this->entityManager->remove($execution);
            $count++;
        }

        if ($count > 0) {
            $this->entityManager->flush();
        }

        return $count;
    }

    /**
     * Get statistics for idempotency keys.
     * 
     * @return array Statistics about idempotency keys
     */
    public function getStatistics(): array
    {
        $conn = $this->entityManager->getConnection();
        
        $sql = 'SELECT 
            COUNT(*) as total,
            COUNT(CASE WHEN status = \'completed\' THEN 1 END) as completed,
            COUNT(CASE WHEN status = \'running\' THEN 1 END) as running,
            COUNT(CASE WHEN status = \'failed\' THEN 1 END) as failed,
            COUNT(CASE WHEN idempotency_key IS NOT NULL THEN 1 END) as with_idempotency
            FROM agent_execution
            WHERE idempotency_key IS NOT NULL';
        
        $stmt = $conn->prepare($sql);
        $result = $stmt->executeQuery();
        
        return $result->fetchAssociative();
    }
}
