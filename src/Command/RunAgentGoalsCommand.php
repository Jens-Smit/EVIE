<?php

namespace App\Command;

use App\Message\RunAgentGoalMessage;
use App\Repository\AgentGoalRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Command zum Ausführen aller fälligen Agent-Ziele.
 * Wird per Cron aufgerufen und dispatcht Nachrichten an den Messenger.
 */
#[AsCommand(
    name: 'app:agent:run-goals',
    description: 'Führt alle fälligen Agent-Ziele autonom aus'
)]
class RunAgentGoalsCommand extends Command
{
    private bool $shouldRun = true;

    public function __construct(
        private AgentGoalRepository $agentGoalRepo,
        private MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'user',
            null,
            InputOption::VALUE_OPTIONAL,
            'Führe nur Ziele für diesen Benutzer aus (user_identifier)',
            null
        );
        
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Zeige nur die zu ausführenden Ziele an, ohne sie auszuführen'
        );
        
        $this->addOption(
            'force',
            null,
            InputOption::VALUE_NONE,
            'Führe alle aktiven Ziele aus, unabhängig vom Fälligkeitsdatum'
        );

        $this->addOption(
            'loop',
            null,
            InputOption::VALUE_OPTIONAL,
            'Führe fällige Ziele in einer Endlosschleife aus (Poll-Intervall in Sekunden, Default 60)',
            ''
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $loopOption = $input->getOption('loop');
        if (is_string($loopOption) && $loopOption !== '') {
            $interval = max(1, (int) $loopOption ?: 60);
            return $this->executeLoop($io, $interval);
        }
        
        $userIdentifier = $input->getOption('user');
        $dryRun = (bool) $input->getOption('dry-run');
        $force = (bool) $input->getOption('force');

        $io->title('EVIE - Autonome Agent-Ziele ausführen');

        // Hole fällige Ziele
        if (null !== $userIdentifier) {
            if ($force) {
                $goals = $this->agentGoalRepo->findActiveByUser($userIdentifier);
            } else {
                $goals = $this->agentGoalRepo->findDueGoalsByUser($userIdentifier);
            }
        } else {
            if ($force) {
                $goals = $this->agentGoalRepo->findBy(['status' => 'active', 'isApproved' => true]);
            } else {
                $goals = $this->agentGoalRepo->findDueGoals();
            }
        }

        if (empty($goals)) {
            $io->info('Keine fälligen Ziele zum Ausführen gefunden.');
            return Command::SUCCESS;
        }

        $io->text(sprintf('Gefundene Ziele: %d', count($goals)));

        foreach ($goals as $goal) {
            $io->section(sprintf('Ziel: %s (ID: %d)', $goal->getTitle(), $goal->getId()));
            
            if ($dryRun) {
                $io->text(sprintf(
                    '  Benutzer: %s | Nächster Lauf: %s | Ausführungen: %d',
                    $goal->getUserIdentifier(),
                    $goal->getNextRunAt()?->format('Y-m-d H:i:s') ?? 'N/A',
                    $goal->getExecutionCount() ?? 0
                ));
                continue;
            }

            // Dispatch Nachricht für die Ausführung
            $message = new RunAgentGoalMessage(
                $goal->getId(),
                $goal->getUserIdentifier(),
                $goal->getTitle(),
                $goal->getCapabilityConstraints()
            );

            $this->messageBus->dispatch($message);
            
            $io->success(sprintf(
                'Nachricht dispatcht für Ziel %d',
                $goal->getId()
            ));

            // Aktualisiere das nächste Laufdatum
            $this->agentGoalRepo->updateNextRunAt($goal);
        }

        if ($dryRun) {
            $io->warning('Dry-Run Modus - keine Nachrichten wurden gesendet');
        } else {
            $io->success(sprintf('Alle %d Ziele wurden zur Ausführung eingereiht', count($goals)));
        }

        return Command::SUCCESS;
    }

    /**
     * Scheduler-Loop: pollt periodisch faellige Ziele und dispatcht sie.
     * Wird vom eigenen Scheduler-Container (docker-compose) genutzt, damit
     * autonome Ziele ohne externes Cron-System ausgefuehrt werden.
     */
    private function executeLoop(SymfonyStyle $io, int $interval): int
    {
        $io->title(sprintf('EVIE Scheduler - Poll-Intervall: %d Sekunden', $interval));

        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, function () use ($io): void {
                $io->writeln('SIGTERM empfangen - Scheduler wird beendet.');
                $this->shouldRun = false;
            });
            pcntl_signal(SIGINT, function () use ($io): void {
                $io->writeln('SIGINT empfangen - Scheduler wird beendet.');
                $this->shouldRun = false;
            });
        }

        while ($this->shouldRun) {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            $this->dispatchDueGoals($io);

            $slept = 0;
            while ($this->shouldRun && $slept < $interval) {
                sleep(1);
                $slept++;
                if (function_exists('pcntl_signal_dispatch')) {
                    pcntl_signal_dispatch();
                }
            }
        }

        $io->success('Scheduler beendet.');

        return Command::SUCCESS;
    }

    /**
     * Ermittelt faellige Ziele und dispatcht RunAgentGoalMessage fuer jedes.
     * Ohne Cron-Expression wird nextRunAt auf null gesetzt, damit Einmal-
     * Ausfuehrungen nicht erneut dispatch werden.
     */
    private function dispatchDueGoals(SymfonyStyle $io): void
    {
        try {
            $goals = $this->agentGoalRepo->findDueGoals();
        } catch (\Exception $e) {
            $io->error('Fehler beim Ermitteln faelliger Ziele: ' . $e->getMessage());
            return;
        }

        if (empty($goals)) {
            $io->writeln(sprintf('[%s] Keine faelligen Ziele.', (new \DateTimeImmutable())->format('Y-m-d H:i:s')));
            return;
        }

        foreach ($goals as $goal) {
            $message = new RunAgentGoalMessage(
                $goal->getId(),
                $goal->getUserIdentifier(),
                $goal->getTitle(),
                $goal->getCapabilityConstraints()
            );

            $this->messageBus->dispatch($message);

            $nextRunAt = $goal->calculateNextRunAt();
            if ($nextRunAt !== null && $goal->getCronExpression() !== null) {
                $goal->setNextRunAt($nextRunAt);
            } else {
                $goal->setNextRunAt(null);
            }
            $this->agentGoalRepo->updateNextRunAt($goal);

            $io->writeln(sprintf(
                '[%s] Ziel %d (%s) dispatcht.',
                (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                $goal->getId() ?? 0,
                $goal->getTitle()
            ));
        }
    }
}
