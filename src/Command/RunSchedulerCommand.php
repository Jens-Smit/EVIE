<?php

namespace App\Command;

use App\Service\Automation\SchedulerService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * RunSchedulerCommand executes scheduled tasks.
 * 
 * This command can be run:
 * - Manually: php bin/console scheduler:run
 * - As a cron job: * * * * * php bin/console scheduler:run
 * - As a daemon: php bin/console scheduler:run --daemon
 * 
 * Features:
 * - Finds and executes due tasks
 * - Supports all tenants or specific tenant
 * - Can run as a long-running daemon
 * - Configurable interval between runs
 */
#[AsCommand(
    name: 'scheduler:run',
    description: 'Execute scheduled tasks',
    aliases: ['evie:scheduler:run']
)]
class RunSchedulerCommand extends Command
{
    public function __construct(
        private SchedulerService $schedulerService,
        private int $defaultInterval = 60
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Execute scheduled tasks')
            ->addOption(
                'tenant',
                't',
                InputOption::VALUE_OPTIONAL,
                'Execute tasks for a specific tenant only',
                null
            )
            ->addOption(
                'daemon',
                'd',
                InputOption::VALUE_NONE,
                'Run as a daemon (continuous execution)'
            )
            ->addOption(
                'interval',
                'i',
                InputOption::VALUE_OPTIONAL,
                'Interval in seconds between runs (for daemon mode)',
                $this->defaultInterval
            )
            ->addOption(
                'max-iterations',
                'm',
                InputOption::VALUE_OPTIONAL,
                'Maximum number of iterations (for daemon mode, 0 = unlimited)',
                0
            )
            ->addOption(
                'once',
                'o',
                InputOption::VALUE_NONE,
                'Execute once and exit (default)'
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Force execution even if tasks are locked'
            )
            ->addOption(
                'verbose',
                'v',
                InputOption::VALUE_NONE,
                'Verbose output'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $tenantId = $input->getOption('tenant');
        $daemonMode = (bool)$input->getOption('daemon');
        $interval = (int)$input->getOption('interval');
        $maxIterations = (int)$input->getOption('max-iterations');
        $once = (bool)$input->getOption('once');
        $force = (bool)$input->getOption('force');
        $verbose = (bool)$input->getOption('verbose');

        // Validate options
        if ($daemonMode && $once) {
            $io->error('Cannot use both --daemon and --once options');
            return Command::INVALID;
        }

        if ($interval < 1) {
            $io->error('Interval must be at least 1 second');
            return Command::INVALID;
        }

        if ($maxIterations < 0) {
            $io->error('Max iterations must be 0 or greater');
            return Command::INVALID;
        }

        $io->title('EVIE Scheduler');
        
        if ($tenantId) {
            $io->text("Tenant: <info>{$tenantId}</info>");
        } else {
            $io->text('<info>All tenants</info>');
        }

        if ($daemonMode) {
            $io->text("Mode: <comment>Daemon</comment>");
            $io->text("Interval: <info>{$interval} seconds</info>");
            if ($maxIterations > 0) {
                $io->text("Max iterations: <info>{$maxIterations}</info>");
            } else {
                $io->text("Max iterations: <info>Unlimited</info>");
            }
        } else {
            $io->text("Mode: <comment>Single run</comment>");
        }

        if ($force) {
            $io->text("Force: <comment>Yes</comment>");
        }

        $io->newLine();

        $iteration = 0;
        $totalExecuted = 0;
        $totalFailed = 0;
        $totalSkipped = 0;

        try {
            do {
                $iteration++;
                
                if ($daemonMode && $iteration > 1) {
                    $io->text("<fg=blue>Waiting {$interval} seconds before next run...</>");
                    sleep($interval);
                    $io->newLine();
                }

                if ($daemonMode) {
                    $io->section("Iteration {$iteration}");
                }

                // Execute due tasks
                $now = new \DateTimeImmutable();
                
                if ($tenantId) {
                    $results = $this->schedulerService->executeDueTasks($tenantId, $now);
                } else {
                    // For now, we'll execute for all tenants
                    // In a real implementation, you would iterate through all tenants
                    $results = [];
                    $tenants = $this->getAllTenants(); // This would be implemented
                    
                    foreach ($tenants as $tenant) {
                        $tenantResults = $this->schedulerService->executeDueTasks($tenant->getId(), $now);
                        $results = array_merge($results, $tenantResults);
                    }
                }

                // Process results
                foreach ($results as $result) {
                    switch ($result['status']) {
                        case 'success':
                            $totalExecuted++;
                            if ($verbose) {
                                $io->success("Task executed: {$result['taskId']}");
                            }
                            break;

                        case 'failed':
                            $totalFailed++;
                            $io->error("Task failed: {$result['taskId']} - {$result['error']}");
                            break;

                        case 'skipped':
                            $totalSkipped++;
                            if ($verbose) {
                                $io->warning("Task skipped: {$result['taskId']} - {$result['reason']}");
                            }
                            break;
                    }
                }

                if (empty($results)) {
                    $io->text('<fg=yellow>No tasks due for execution</>');
                } else {
                    $io->text(sprintf(
                        'Executed: <fg=green>%d</>, Failed: <fg=red>%d</>, Skipped: <fg=yellow>%d</>',
                        count(array_filter($results, fn($r) => $r['status'] === 'success')),
                        count(array_filter($results, fn($r) => $r['status'] === 'failed')),
                        count(array_filter($results, fn($r) => $r['status'] === 'skipped'))
                    ));
                }

                $io->newLine();

                // Check if we should stop
                if ($once) {
                    break;
                }

                if ($daemonMode && $maxIterations > 0 && $iteration >= $maxIterations) {
                    $io->text("Max iterations reached, stopping");
                    break;
                }

            } while ($daemonMode);

            // Summary
            $io->section('Summary');
            $io->text(sprintf('Total iterations: <info>%d</info>', $iteration));
            $io->text(sprintf('Total executed: <info>%d</info>', $totalExecuted));
            $io->text(sprintf('Total failed: <info>%d</info>', $totalFailed));
            $io->text(sprintf('Total skipped: <info>%d</info>', $totalSkipped));

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error($e->getMessage());
            
            if ($verbose) {
                $io->text($e->getTraceAsString());
            }
            
            return Command::FAILURE;
        }
    }

    /**
     * Get all tenants (placeholder for real implementation).
     * 
     * @return array Array of tenant entities
     */
    private function getAllTenants(): array
    {
        // In a real implementation, you would:
        // 1. Get the TenantRepository
        // 2. Find all active tenants
        // 3. Return the array
        
        // For now, return empty array (single tenant mode)
        return [];
    }
}
