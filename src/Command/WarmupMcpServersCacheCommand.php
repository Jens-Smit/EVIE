<?php
// src/Command/WarmupMcpServersCacheCommand.php

namespace App\Command;

use App\Repository\McpServerDefinitionRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Command zum Warmup des Caches für MCP-Server-Definitionen.
 * Lädt alle aktiven MCP-Server aus der Datenbank und speichert sie im Cache,
 * damit der AiMcpServersCompilerPass zur Compile-Time darauf zugreifen kann.
 */
#[AsCommand(
    name: 'evie:mcp-servers:warmup-cache',
    description: 'Lädt alle aktiven MCP-Server aus der Datenbank und registriert sie im Cache.'
)]
class WarmupMcpServersCacheCommand extends Command
{
    private McpServerDefinitionRepository $mcpServerDefinitionRepo;
    private CacheInterface $cache;

    public function __construct(
        McpServerDefinitionRepository $mcpServerDefinitionRepo,
        CacheInterface $cache
    ) {
        $this->mcpServerDefinitionRepo = $mcpServerDefinitionRepo;
        $this->cache = $cache;
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('EVIE MCP-Server Cache Warmup');

        // 1. Lade alle aktiven MCP-Server-Definitionen aus der Datenbank
        $definitions = $this->mcpServerDefinitionRepo->findAllActive();

        if (empty($definitions)) {
            $io->warning('Keine aktiven MCP-Server-Definitionen gefunden.');
            return Command::SUCCESS;
        }

        // 2. Speichere die Definitionen im Cache für den CompilerPass
        $this->cache->set('ai.mcp_server.definitions', $definitions);

        $io->section('Gefundene MCP-Server-Definitionen:');
        foreach ($definitions as $definition) {
            $io->text(sprintf(
                '- %s (Typ: %s, Aktiv: %s)',
                $definition->getName(),
                $definition->getType(),
                $definition->isActive() ? 'Ja' : 'Nein'
            ));
        }

        $io->success(sprintf(
            '%d MCP-Server-Definitionen wurden geladen und gecacht.',
            count($definitions)
        ));

        // 3. Gib Informationen zu den geladenen Servern aus
        $this->displayServerDetails($io, $definitions);

        return Command::SUCCESS;
    }

    /**
     * Zeigt detaillierte Informationen zu den geladenen MCP-Servern an.
     */
    private function displayServerDetails(SymfonyStyle $io, array $definitions): void
    {
        $io->newLine();
        $io->section('Detaillierte Server-Informationen:');

        foreach ($definitions as $definition) {
            $io->text(sprintf('<comment>Name:</comment> %s', $definition->getName()));
            $io->text(sprintf('<comment>Typ:</comment> %s', $definition->getType()));
            $io->text(sprintf('<comment>Beschreibung:</comment> %s', $definition->getDescription()));
            
            if (!empty($definition->getAllowedTools())) {
                $io->text(sprintf('<comment>Erlaubte Tools:</comment> %s', implode(', ', $definition->getAllowedTools())));
            }
            
            if (!empty($definition->getBlockedResources())) {
                $io->text(sprintf('<comment>Blockierte Ressourcen:</comment> %s', implode(', ', $definition->getBlockedResources())));
            }
            
            $io->newLine();
        }
    }
}
