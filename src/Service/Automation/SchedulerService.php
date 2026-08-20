<?php

namespace App\Service\Automation;

use App\Entity\Automation\ScheduledTask;
use App\Entity\Tenant\Tenant;
use App\Message\ExecuteAgentMessage;
use App\Repository\Automation\ScheduledTaskRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Ulid;

/**
 * SchedulerService manages scheduled task execution.
 * 
 * Features:
 * - Due query implementation (find tasks that should run now)
 * - Task execution with locking
 * - Recurrence support (once, hourly, daily, weekly, monthly, cron)
 * - Timezone handling
 * - DST (Daylight Saving Time) support
 * - Task lifecycle management
 */
class SchedulerService
{
    private const string LOCK_PREFIX = 'scheduler_lock_';
    private const int LOCK_TTL = 3600; // 1 hour in seconds
    private const int MAX_CONCURRENT_TASKS = 10;

    public function __construct(
        private ScheduledTaskRepository $taskRepository,
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
        private string $defaultTimezone = 'UTC'
    ) {
    }

    /**
     * Find tasks that are due for execution.
     * 
     * @param string $tenantId The tenant ID to filter by
     * @param \DateTimeImmutable $now The current time
     * @return ScheduledTask[] Array of due tasks
     */
    public function findDueTasks(string $tenantId, \DateTimeImmutable $now): array
    {
        return $this->taskRepository->findDueTasks($tenantId, $now);
    }

    /**
     * Find and execute due tasks.
     * 
     * @param string $tenantId The tenant ID
     * @param \DateTimeImmutable|null $now The current time (defaults to now)
     * @return array Execution results
     */
    public function executeDueTasks(string $tenantId, ?\DateTimeImmutable $now = null): array
    {
        $now = $now ?? new \DateTimeImmutable();
        $results = [];

        // Find due tasks
        $dueTasks = $this->findDueTasks($tenantId, $now);

        $this->logger->debug('Found due tasks for execution', [
            'tenantId' => $tenantId,
            'count' => count($dueTasks),
        ]);

        // Limit concurrent tasks
        $executedCount = 0;
        foreach ($dueTasks as $task) {
            if ($executedCount >= self::MAX_CONCURRENT_TASKS) {
                $this->logger->warning('Max concurrent tasks reached, stopping execution', [
                    'tenantId' => $tenantId,
                    'maxConcurrent' => self::MAX_CONCURRENT_TASKS,
                ]);
                break;
            }

            try {
                $result = $this->executeTask($task, $now);
                $results[] = $result;
                $executedCount++;
            } catch (\Exception $e) {
                $this->logger->error('Failed to execute task', [
                    'taskId' => $task->getId(),
                    'error' => $e->getMessage(),
                ]);
                continue;
            }
        }

        return $results;
    }

    /**
     * Execute a single scheduled task.
     * 
     * @param ScheduledTask $task The task to execute
     * @param \DateTimeImmutable $now The current time
     * @return array Execution result
     */
    public function executeTask(ScheduledTask $task, \DateTimeImmutable $now): array
    {
        $taskId = $task->getId();
        $tenantId = $task->getTenantId();

        $this->logger->info('Executing scheduled task', [
            'taskId' => $taskId,
            'name' => $task->getName(),
            'tenantId' => $tenantId,
        ]);

        // Step 1: Try to lock the task
        if (!$this->lockTask($task)) {
            $this->logger->warning('Task is locked by another worker, skipping', [
                'taskId' => $taskId,
            ]);
            return [
                'status' => 'skipped',
                'taskId' => $taskId,
                'reason' => 'locked',
            ];
        }

        try {
            // Step 2: Update task status
            $task->setStatus('running');
            $task->setLastRunAt($now);
            $task->incrementRunCount();
            $this->entityManager->flush();

            // Step 3: Execute the task action
            $result = $this->executeTaskAction($task);

            // Step 4: Update task status and next run time
            $task->setStatus('completed');
            $task->setLastStatus('success');
            $task->setLastError(null);
            $task->setLastResult($result);
            $task->setNextRunAt($this->calculateNextRunAt($task));
            $this->entityManager->flush();

            $this->logger->info('Task executed successfully', [
                'taskId' => $taskId,
                'nextRunAt' => $task->getNextRunAt()?->format('c'),
            ]);

            return [
                'status' => 'success',
                'taskId' => $taskId,
                'result' => $result,
                'nextRunAt' => $task->getNextRunAt()?->format('c'),
            ];

        } catch (\Exception $e) {
            // Step 5: Handle execution error
            $this->handleTaskError($task, $e);
            
            return [
                'status' => 'failed',
                'taskId' => $taskId,
                'error' => $e->getMessage(),
            ];
        } finally {
            // Step 6: Always unlock the task
            $this->unlockTask($task);
        }
    }

    /**
     * Execute the action defined in the task.
     * 
     * @param ScheduledTask $task The task to execute
     * @return array The result of the action
     */
    private function executeTaskAction(ScheduledTask $task): array
    {
        $action = $task->getAction();
        $parameters = $task->getParameters() ?? [];

        $this->logger->debug('Executing task action', [
            'taskId' => $task->getId(),
            'action' => $action,
        ]);

        // Dispatch an ExecuteAgentMessage for the action
        $message = new ExecuteAgentMessage(
            executionId: Ulid::generate(),
            userId: $task->getUserId(),
            tenantId: $task->getTenantId(),
            agentName: $action,
            conversationId: null,
            parentExecutionId: null,
            idempotencyKey: Ulid::generate(),
            correlationId: Ulid::generate(),
            parameters: $parameters,
            metadata: [
                'scheduledTaskId' => $task->getId(),
                'scheduledTaskName' => $task->getName(),
            ],
            scheduledTaskId: $task->getId()
        );

        // Dispatch the message for async execution
        $this->messageBus->dispatch($message);

        return [
            'action' => $action,
            'executionId' => $message->getExecutionId(),
            'status' => 'queued',
            'queuedAt' => (new \DateTimeImmutable())->format('c'),
        ];
    }

    /**
     * Handle a task execution error.
     * 
     * @param ScheduledTask $task The task that failed
     * @param \Exception $e The exception that occurred
     */
    private function handleTaskError(ScheduledTask $task, \Exception $e): void
    {
        $task->setStatus('failed');
        $task->setLastStatus('error');
        $task->setLastError($e->getMessage());
        $task->incrementFailureCount();
        $task->setNextRunAt($this->calculateNextRunAt($task));
        
        $this->entityManager->flush();

        $this->logger->error('Task execution failed', [
            'taskId' => $task->getId(),
            'name' => $task->getName(),
            'error' => $e->getMessage(),
            'failureCount' => $task->getFailureCount(),
            'nextRunAt' => $task->getNextRunAt()?->format('c'),
        ]);
    }

    /**
     * Try to lock a task for execution.
     * 
     * @param ScheduledTask $task The task to lock
     * @return bool True if the task was locked successfully
     */
    public function lockTask(ScheduledTask $task): bool
    {
        $lockId = Ulid::generate();
        $now = new \DateTimeImmutable();

        // Check if task is already locked
        if ($task->isLocked()) {
            return false;
        }

        // Try to lock the task
        $task->setLockId($lockId);
        $task->setLockedAt($now);
        
        $this->entityManager->flush();

        // Verify the lock was set (in case of race condition)
        $this->entityManager->refresh($task);
        
        return $task->getLockId() === $lockId;
    }

    /**
     * Unlock a task.
     * 
     * @param ScheduledTask $task The task to unlock
     */
    public function unlockTask(ScheduledTask $task): void
    {
        $task->setLockId(null);
        $task->setLockedAt(null);
        
        $this->entityManager->flush();
    }

    /**
     * Calculate the next run time for a task based on its schedule.
     * 
     * @param ScheduledTask $task The task
     * @return \DateTimeImmutable The next run time
     */
    public function calculateNextRunAt(ScheduledTask $task): \DateTimeImmutable
    {
        $schedule = $task->getSchedule();
        $timezone = new \DateTimeZone($task->getTimezone());
        $now = new \DateTimeImmutable('now', $timezone);
        
        // If there's a last run time, start from there, otherwise start from now
        $startFrom = $task->getLastRunAt() ?? $now;
        
        // For now, use the task's own calculation
        return $task->calculateNextRunAt();
    }

    /**
     * Parse a schedule from a structured array or natural language.
     * 
     * @param array|string $schedule The schedule definition
     * @param string $timezone The timezone
     * @return array Parsed schedule
     */
    public function parseSchedule($schedule, string $timezone = 'UTC'): array
    {
        // If it's already an array, validate and return
        if (is_array($schedule)) {
            return $this->validateSchedule($schedule, $timezone);
        }

        // If it's a string, try to parse as natural language
        if (is_string($schedule)) {
            return $this->parseNaturalLanguageSchedule($schedule, $timezone);
        }

        throw new \InvalidArgumentException(
            'Schedule must be an array or string, got: ' . gettype($schedule)
        );
    }

    /**
     * Validate a schedule array.
     * 
     * @param array $schedule The schedule to validate
     * @param string $timezone The timezone
     * @return array Validated schedule
     */
    public function validateSchedule(array $schedule, string $timezone): array
    {
        $validated = [
            'frequency' => $schedule['frequency'] ?? 'once',
            'timezone' => $timezone,
        ];

        // Validate frequency
        $validFrequencies = ['once', 'hourly', 'daily', 'weekly', 'monthly', 'cron'];
        if (!in_array($validated['frequency'], $validFrequencies)) {
            throw new \InvalidArgumentException(
                "Invalid frequency: {$validated['frequency']}. Valid: " . implode(', ', $validFrequencies)
            );
        }

        // Add frequency-specific fields
        switch ($validated['frequency']) {
            case 'once':
                $validated['time'] = $schedule['time'] ?? null;
                break;

            case 'hourly':
                $validated['minute'] = $schedule['minute'] ?? 0;
                break;

            case 'daily':
                $validated['time'] = $schedule['time'] ?? '00:00';
                break;

            case 'weekly':
                $validated['day'] = $schedule['day'] ?? 'monday';
                $validated['time'] = $schedule['time'] ?? '00:00';
                break;

            case 'monthly':
                $validated['day'] = $schedule['day'] ?? 1;
                $validated['time'] = $schedule['time'] ?? '00:00';
                break;

            case 'cron':
                $validated['expression'] = $schedule['expression'] ?? '0 * * * *';
                break;
        }

        return $validated;
    }

    /**
     * Parse a natural language schedule string.
     * 
     * @param string $text The natural language text
     * @param string $timezone The timezone
     * @return array Parsed schedule
     */
    public function parseNaturalLanguageSchedule(string $text, string $timezone): array
    {
        $text = strtolower(trim($text));

        // Remove common prefixes
        $text = preg_replace('/^(every|each|at|on|in)\s+/i', '', $text);

        // Parse frequency
        $frequency = 'once';
        $time = null;
        $day = null;

        if (preg_match('/(hourly|every hour|each hour)/i', $text)) {
            $frequency = 'hourly';
        } elseif (preg_match('/(daily|every day|each day)/i', $text)) {
            $frequency = 'daily';
        } elseif (preg_match('/(weekly|every week|each week)/i', $text)) {
            $frequency = 'weekly';
        } elseif (preg_match('/(monthly|every month|each month)/i', $text)) {
            $frequency = 'monthly';
        }

        // Parse day of week for weekly
        if ($frequency === 'weekly') {
            $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
            foreach ($days as $dayName) {
                if (preg_match('/' . $dayName . '/i', $text)) {
                    $day = $dayName;
                    break;
                }
            }
        }

        // Parse time
        if (preg_match('/(\d{1,2}:\d{2})/', $text, $matches)) {
            $time = $matches[1];
        } elseif (preg_match('/(\d{1,2})\s*(am|pm)/i', $text, $matches)) {
            $hour = (int)$matches[1];
            $period = strtolower($matches[2]);
            
            if ($period === 'pm' && $hour < 12) {
                $hour += 12;
            } elseif ($period === 'am' && $hour === 12) {
                $hour = 0;
            }
            
            $time = sprintf('%02d:00', $hour);
        }

        // Parse day of month for monthly
        if ($frequency === 'monthly') {
            if (preg_match('/(\d{1,2})(st|nd|rd|th)/', $text, $matches)) {
                $day = (int)$matches[1];
            } elseif (preg_match('/(\d{1,2})\s*(of the month)/i', $text, $matches)) {
                $day = (int)$matches[1];
            }
        }

        $schedule = [
            'frequency' => $frequency,
            'timezone' => $timezone,
        ];

        if ($time !== null) {
            $schedule['time'] = $time;
        }

        if ($day !== null) {
            $schedule['day'] = $day;
        }

        return $this->validateSchedule($schedule, $timezone);
    }

    /**
     * Convert a natural language schedule to a Schedule DTO.
     * 
     * @param string $text The natural language text
     * @param string $timezone The timezone
     * @return array Schedule DTO
     */
    public function naturalLanguageToScheduleDTO(string $text, string $timezone): array
    {
        return $this->parseNaturalLanguageSchedule($text, $timezone);
    }

    /**
     * Create a scheduled task from natural language.
     * 
     * @param string $name The task name
     * @param string $scheduleText The natural language schedule
     * @param string $action The action to execute
     * @param string $userId The user ID
     * @param string $tenantId The tenant ID
     * @param array $parameters The action parameters
     * @param array $metadata Additional metadata
     * @return ScheduledTask The created task
     */
    public function createTaskFromNaturalLanguage(
        string $name,
        string $scheduleText,
        string $action,
        string $userId,
        string $tenantId,
        array $parameters = [],
        array $metadata = []
    ): ScheduledTask {
        $task = new ScheduledTask();
        
        $task->setName($name);
        $task->setAction($action);
        $task->setParameters($parameters);
        $task->setMetadata($metadata);

        // Parse the schedule
        $schedule = $this->parseNaturalLanguageSchedule($scheduleText, $this->defaultTimezone);
        $task->setSchedule($schedule);
        $task->setTimezone($schedule['timezone']);

        // Calculate first run time
        $task->setNextRunAt($this->calculateNextRunAt($task));

        // Set user and tenant (will be set by the caller)
        // $task->setUser($user);
        // $task->setTenant($tenant);

        $this->entityManager->persist($task);
        $this->entityManager->flush();

        return $task;
    }

    /**
     * Get statistics about scheduled tasks.
     * 
     * @param string $tenantId The tenant ID
     * @return array Statistics
     */
    public function getStatistics(string $tenantId): array
    {
        $conn = $this->entityManager->getConnection();
        
        $sql = 'SELECT 
            COUNT(*) as total,
            COUNT(CASE WHEN status = \'pending\' THEN 1 END) as pending,
            COUNT(CASE WHEN status = \'running\' THEN 1 END) as running,
            COUNT(CASE WHEN status = \'completed\' THEN 1 END) as completed,
            COUNT(CASE WHEN status = \'failed\' THEN 1 END) as failed,
            COUNT(CASE WHEN status = \'dead_letter\' THEN 1 END) as dead_letter,
            AVG(run_count) as avg_runs,
            MAX(run_count) as max_runs
            FROM scheduled_task 
            WHERE tenant_id = :tenantId';
        
        $stmt = $conn->prepare($sql);
        $stmt->executeQuery(['tenantId' => $tenantId]);
        
        return $stmt->fetchAssociative();
    }

    /**
     * Get the next run time for all due tasks.
     * 
     * @param string $tenantId The tenant ID
     * @return \DateTimeImmutable|null The next run time or null if no tasks
     */
    public function getNextRunTime(string $tenantId): ?\DateTimeImmutable
    {
        $dueTasks = $this->findDueTasks($tenantId, new \DateTimeImmutable());
        
        if (empty($dueTasks)) {
            return null;
        }

        $nextRunAt = null;
        foreach ($dueTasks as $task) {
            $taskNextRun = $task->getNextRunAt();
            if ($taskNextRun !== null) {
                if ($nextRunAt === null || $taskNextRun < $nextRunAt) {
                    $nextRunAt = $taskNextRun;
                }
            }
        }

        return $nextRunAt;
    }
}
