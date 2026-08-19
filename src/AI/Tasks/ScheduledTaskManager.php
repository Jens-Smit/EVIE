<?php

namespace App\AI\Tasks;

use App\Entity\ScheduledTask;
use App\Entity\User;
use App\Message\ExecuteSubAgentMessage;
use App\Repository\ScheduledTaskRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Manager für geplante Aufgaben
 * 
 * Erstellt, verwaltet und führt geplante Aufgaben aus.
 */
class ScheduledTaskManager
{
    public function __construct(
        private ScheduledTaskRepository $taskRepo,
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Erstellt eine neue geplante Aufgabe
     * 
     * @param User $user Der User
     * @param string $taskDescription Die Beschreibung der Aufgabe
     * @param string $taskType Der Typ der Aufgabe
     * @param array $parameters Parameter für die Aufgabe
     * @param \DateTimeImmutable|null Der Zeitpunkt, zu dem die Aufgabe ausgeführt werden soll
     * @param bool $isRecurring Ob die Aufgabe wiederkehrend ist
     * @param string|null Das Wiederholungsmuster
     * @param int|null Das Wiederholungsintervall
     * @return ScheduledTask Die erstellte Aufgabe
     */
    public function createTask(
        User $user,
        string $taskDescription,
        string $taskType,
        array $parameters = [],
        ?\DateTimeImmutable $scheduledAt = null,
        bool $isRecurring = false,
        ?string $recurrencePattern = null,
        ?int $recurrenceInterval = null
    ): ScheduledTask {
        $task = new ScheduledTask();
        $task->setUser($user);
        $task->setTaskDescription($taskDescription);
        $task->setTaskType($taskType);
        $task->setParameters($parameters);
        $task->setScheduledAt($scheduledAt ?? new \DateTimeImmutable('+1 hour'));
        $task->setIsRecurring($isRecurring);
        $task->setRecurrencePattern($recurrencePattern);
        $task->setRecurrenceInterval($recurrenceInterval);

        if ($isRecurring) {
            $task->setNextExecutionAt($scheduledAt);
        }

        $this->entityManager->persist($task);
        $this->entityManager->flush();

        $this->logger->info('Neue geplante Aufgabe erstellt', [
            'task_id' => $task->getId(),
            'task_type' => $taskType,
            'user_id' => $user->getId(),
            'scheduled_at' => $task->getScheduledAt()->format('c')
        ]);

        return $task;
    }

    /**
     * Führt eine geplante Aufgabe aus
     * 
     * @param ScheduledTask $task Die auszuführende Aufgabe
     */
    public function executeTask(ScheduledTask $task): void
    {
        try {
            $task->setStatus('executing');
            $task->setExecutedAt(new \DateTimeImmutable());
            $this->entityManager->flush();

            // Aufgabe basierend auf dem Typ ausführen
            $result = $this->executeTaskByType($task);

            $task->setStatus('executed');
            $task->setResult($result);

            // Nächste Ausführung berechnen, falls wiederkehrend
            if ($task->isRecurring()) {
                $task->setNextExecutionAt($this->calculateNextExecution($task));
            }

            $this->entityManager->flush();

            $this->logger->info('Geplante Aufgabe erfolgreich ausgeführt', [
                'task_id' => $task->getId(),
                'task_type' => $task->getTaskType(),
                'user_id' => $task->getUser()->getId()
            ]);

        } catch (\Exception $e) {
            $task->setStatus('failed');
            $task->setErrorMessage($e->getMessage());
            $this->entityManager->flush();

            $this->logger->error('Fehler bei geplanter Aufgabe: ' . $e->getMessage(), [
                'task_id' => $task->getId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Führt eine Aufgabe basierend auf ihrem Typ aus
     * 
     * @param ScheduledTask $task Die Aufgabe
     * @return string Das Ergebnis der Ausführung
     */
    private function executeTaskByType(ScheduledTask $task): string
    {
        $user = $task->getUser();
        $parameters = $task->getParameters();

        return match ($task->getTaskType()) {
            'check_email' => $this->executeCheckEmail($user, $parameters),
            'create_briefing' => $this->executeCreateBriefing($user, $parameters),
            'custom' => $this->executeCustomTask($user, $task->getTaskDescription(), $parameters),
            default => throw new \RuntimeException("Unbekannter Task-Typ: " . $task->getTaskType())
        };
    }

    /**
     * Führt eine E-Mail-Prüfung aus
     */
    private function executeCheckEmail(User $user, array $parameters): string
    {
        // In einer echten Implementierung würde hier die E-Mail geprüft werden
        // Für jetzt geben wir eine Platzhalter-Nachricht zurück
        return "E-Mail-Prüfung abgeschlossen. 5 neue E-Mails gefunden.";
    }

    /**
     * Erstellt ein Briefing
     */
    private function executeCreateBriefing(User $user, array $parameters): string
    {
        // In einer echten Implementierung würde hier ein Briefing erstellt werden
        // Für jetzt geben wir eine Platzhalter-Nachricht zurück
        return "Briefing erstellt: Tageszusammenfassung für " . $user->getFullName();
    }

    /**
     * Führt eine benutzerdefinierte Aufgabe aus
     */
    private function executeCustomTask(User $user, string $taskDescription, array $parameters): string
    {
        // Delegiere an einen Sub-Agenten
        $message = new ExecuteSubAgentMessage(
            $user->getUserIdentifier(),
            'ceo_assistant',
            $taskDescription,
            $parameters
        );

        $this->messageBus->dispatch($message);

        return "Aufgabe an Sub-Agenten delegiert: $taskDescription";
    }

    /**
     * Berechnet das nächste Ausführungsdatum für wiederkehrende Aufgaben
     * 
     * @param ScheduledTask $task Die Aufgabe
     * @return \DateTimeImmutable Das nächste Ausführungsdatum
     */
    private function calculateNextExecution(ScheduledTask $task): \DateTimeImmutable
    {
        $lastExecution = $task->getExecutedAt() ?? $task->getScheduledAt();
        $pattern = $task->getRecurrencePattern();
        $interval = $task->getRecurrenceInterval() ?? 1;

        return match ($pattern) {
            'daily' => $lastExecution->modify("+$interval days"),
            'weekly' => $lastExecution->modify("+$interval weeks"),
            'monthly' => $lastExecution->modify("+$interval months"),
            'hourly' => $lastExecution->modify("+$interval hours"),
            'custom' => $this->calculateCustomRecurrence($task, $lastExecution),
            default => throw new \RuntimeException("Unbekanntes Recurrence-Pattern: $pattern")
        };
    }

    /**
     * Berechnet benutzerdefinierte Wiederholung
     */
    private function calculateCustomRecurrence(ScheduledTask $task, \DateTimeImmutable $lastExecution): \DateTimeImmutable
    {
        // In einer echten Implementierung würde hier ein benutzerdefiniertes
        // Cron-ähnliches Pattern verarbeitet werden
        // Für jetzt geben wir einfach +1 Tag zurück
        return $lastExecution->modify('+1 day');
    }

    /**
     * Storniert eine geplante Aufgabe
     * 
     * @param ScheduledTask $task Die Aufgabe
     */
    public function cancelTask(ScheduledTask $task): void
    {
        $task->setStatus('cancelled');
        $task->setIsActive(false);
        $this->entityManager->flush();

        $this->logger->info('Geplante Aufgabe storniert', [
            'task_id' => $task->getId()
        ]);
    }

    /**
     * Aktiviert eine geplante Aufgabe
     * 
     * @param ScheduledTask $task Die Aufgabe
     */
    public function activateTask(ScheduledTask $task): void
    {
        $task->setIsActive(true);
        $task->setStatus('pending');
        $this->entityManager->flush();

        $this->logger->info('Geplante Aufgabe aktiviert', [
            'task_id' => $task->getId()
        ]);
    }

    /**
     * Gibt alle ausstehenden Aufgaben zurück
     * 
     * @return array Array von ScheduledTask-Entitäten
     */
    public function getPendingTasks(): array
    {
        $now = new \DateTimeImmutable();
        
        return $this->taskRepo->findBy([
            'status' => ['pending', 'executing'],
            'isActive' => true,
            'scheduledAt' => $now
        ], ['scheduledAt' => 'ASC']);
    }

    /**
     * Gibt alle Aufgaben zurück, die in den nächsten X Minuten fällig sind
     * 
     * @param int $minutes Die Anzahl der Minuten
     * @return array Array von ScheduledTask-Entitäten
     */
    public function getDueTasks(int $minutes = 5): array
    {
        return $this->taskRepo->findDueWithinMinutes($minutes);
    }

    /**
     * Erstellt eine Aufgabe aus einer natürlichen Sprachbeschreibung
     * 
     * @param User $user Der User
     * @param string $naturalLanguageDescription Die natürliche Sprachbeschreibung
     * @return ScheduledTask|null Die erstellte Aufgabe oder null
     */
    public function createTaskFromNaturalLanguage(
        User $user,
        string $naturalLanguageDescription
    ): ?ScheduledTask {
        // Pattern für geplante Aufgaben
        // z.B. "Prüfe um 9:00 meine Mails", "Mache um 10:00 ein Briefing"
        
        $patterns = [
            '/(prüfe|checke|überprüfe)\s+(um|at)\s+(\d{1,2}:\d{2})\s+(meine|die|die )?(mails|e-mails|emails)/i',
            '/(mache|erstelle|erstelle)\s+(um|at)\s+(\d{1,2}:\d{2})\s+(ein|eine|einen)\s+(briefing|zusammenfassung|bericht)/i',
            '/(erinnere mich|reminder)\s+(um|at)\s+(\d{1,2}:\d{2})\s+(an|an )?(.+)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $naturalLanguageDescription, $matches)) {
                $time = $matches[3] ?? null;
                $taskType = $this->extractTaskTypeFromMessage($naturalLanguageDescription);
                $description = $this->extractTaskDescriptionFromMessage($naturalLanguageDescription);

                if ($time && $taskType) {
                    // Parse die Zeit
                    $timeParts = explode(':', $time);
                    $hour = (int)($timeParts[0] ?? 0);
                    $minute = (int)($timeParts[1] ?? 0);

                    // Erstelle die geplante Aufgabe
                    $scheduledAt = new \DateTimeImmutable();
                    $scheduledAt = $scheduledAt->setTime($hour, $minute, 0);

                    // Falls die Zeit schon vorbei ist, auf morgen setzen
                    if ($scheduledAt < new \DateTimeImmutable()) {
                        $scheduledAt = $scheduledAt->modify('+1 day');
                    }

                    return $this->createTask(
                        $user,
                        $description,
                        $taskType,
                        [],
                        $scheduledAt
                    );
                }
            }
        }

        return null;
    }

    /**
     * Extrahiere den Task-Typ aus der Nachricht
     */
    private function extractTaskTypeFromMessage(string $message): ?string
    {
        $messageLower = strtolower($message);

        if (str_contains($messageLower, 'mail') || str_contains($messageLower, 'e-mail')) {
            return 'check_email';
        }

        if (str_contains($messageLower, 'briefing') || str_contains($messageLower, 'zusammenfassung')) {
            return 'create_briefing';
        }

        return 'custom';
    }

    /**
     * Extrahiere die Task-Beschreibung aus der Nachricht
     */
    private function extractTaskDescriptionFromMessage(string $message): string
    {
        // Entferne Zeitangaben und Task-Typen
        $description = preg_replace('/(prüfe|checke|überprüfe|mache|erstelle|erstelle|erinnere mich)\s+(um|at)\s+\d{1,2}:\d{2}\s+/i', '', $message);
        $description = trim($description);

        return $description ?: $message;
    }

    /**
     * Gibt alle Aufgaben für einen User zurück
     * 
     * @param User $user Der User
     * @return array Array von ScheduledTask-Entitäten
     */
    public function getTasksForUser(User $user): array
    {
        return $this->taskRepo->findAllForUser($user);
    }

    /**
     * Gibt alle ausstehenden Aufgaben für einen User zurück
     * 
     * @param User $user Der User
     * @return array Array von ScheduledTask-Entitäten
     */
    public function getPendingTasksForUser(User $user): array
    {
        return $this->taskRepo->findPendingForUser($user);
    }

    /**
     * Gibt alle wiederkehrenden Aufgaben für einen User zurück
     * 
     * @param User $user Der User
     * @return array Array von ScheduledTask-Entitäten
     */
    public function getRecurringTasksForUser(User $user): array
    {
        return $this->taskRepo->findRecurringForUser($user);
    }

    /**
     * Löscht eine Aufgabe
     * 
     * @param ScheduledTask $task Die Aufgabe
     */
    public function deleteTask(ScheduledTask $task): void
    {
        $this->entityManager->remove($task);
        $this->entityManager->flush();

        $this->logger->info('Geplante Aufgabe gelöscht', [
            'task_id' => $task->getId()
        ]);
    }
}
