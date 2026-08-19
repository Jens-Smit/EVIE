<?php

namespace App\Command;

use App\AI\Tasks\ScheduledTaskManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Command für die Ausführung geplanter Aufgaben
 * 
 * Dieser Command wird regelmäßig (z.B. alle 5 Minuten) via Cron-Job ausgeführt,
 * um alle fälligen geplanten Aufgaben auszuführen.
 */
#[AsCommand(name: 'app:execute-scheduled-tasks')]
class ExecuteScheduledTasksCommand extends Command
{
    public function __construct(
        private ScheduledTaskManager $taskManager,
        private LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Führt alle ausstehenden geplanten Aufgaben aus')
            ->setHelp('Dieser Command prüft alle geplanten Aufgaben und führt die fälligen aus.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $this->logger->info('Starte Ausführung geplanter Aufgaben');
        
        try {
            // Hole alle Aufgaben, die in den nächsten 5 Minuten fällig sind
            $dueTasks = $this->taskManager->getDueTasks(5);
            
            if (empty($dueTasks)) {
                $io->success('Keine fälligen Aufgaben gefunden.');
                $this->logger->info('Keine fälligen Aufgaben gefunden');
                return Command::SUCCESS;
            }
            
            $io->text(sprintf('Führe %d fällige Aufgabe(n) aus...', count($dueTasks)));
            
            $executedCount = 0;
            $failedCount = 0;
            
            foreach ($dueTasks as $task) {
                try {
                    $io->text(sprintf('  - Aufgabe #%d: %s', $task->getId(), $task->getTaskDescription()));
                    
                    $this->taskManager->executeTask($task);
                    $executedCount++;
                    
                } catch (\Exception $e) {
                    $io->error(sprintf('    Fehler bei Aufgabe #%d: %s', $task->getId(), $e->getMessage()));
                    $this->logger->error('Fehler bei geplanter Aufgabe: ' . $e->getMessage(), [
                        'task_id' => $task->getId(),
                        'error' => $e->getMessage()
                    ]);
                    $failedCount++;
                }
            }
            
            $io->success(sprintf('Ausführung abgeschlossen: %d erfolgreich, %d fehlgeschlagen', $executedCount, $failedCount));
            
            $this->logger->info('Ausführung geplanter Aufgaben abgeschlossen', [
                'tasks_executed' => $executedCount,
                'tasks_failed' => $failedCount
            ]);
            
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            $io->error(sprintf('Fehler: %s', $e->getMessage()));
            $this->logger->error('Fehler bei der Ausführung geplanter Aufgaben: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
