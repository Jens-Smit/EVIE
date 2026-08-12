<?php
// src/Command/WarmupSubAgentsCacheCommand.php

namespace App\Command;

use App\Repository\SubAgentDefinitionRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Cache\CacheInterface;

#[AsCommand(
    name: 'evie:subagents:warmup-cache',
    description: 'Lädt alle aktiven Sub-Agenten aus der Datenbank und registriert sie im Cache.'
)]
class WarmupSubAgentsCacheCommand extends Command
{
    private SubAgentDefinitionRepository $subAgentDefinitionRepo;
    private CacheInterface $cache;

    public function __construct(
        SubAgentDefinitionRepository $subAgentDefinitionRepo,
        CacheInterface $cache
    ) {
        $this->subAgentDefinitionRepo = $subAgentDefinitionRepo;
        $this->cache = $cache;
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('EVIE Sub-Agenten Cache Warmup');

        // 1. Lade alle aktiven Sub-Agenten-Definitionen aus der Datenbank
        $definitions = $this->subAgentDefinitionRepo->findAllActive();

        if (empty($definitions)) {
            $io->warning('Keine aktiven Sub-Agenten-Definitionen gefunden.');
            return Command::SUCCESS;
        }

        // 2. Speichere die Definitionen im Cache für den CompilerPass
        $this->cache->set('ai.sub_agent.definitions', $definitions);

        $io->section('Gefundene Sub-Agenten-Definitionen:');
        foreach ($definitions as $definition) {
            $io->text(sprintf(
                '- %s (Klasse: %s, Aktiv: %s)',
                $definition->getName(),
                $definition->getClassName(),
                $definition->isActive() ? 'Ja' : 'Nein'
            ));
        }

        $io->success(sprintf(
            '%d Sub-Agenten-Definitionen wurden geladen und gecacht.',
            count($definitions)
        ));

        return Command::SUCCESS;
    }
}
