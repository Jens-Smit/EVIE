<?php

namespace App\Command;

use App\Repository\ToolDefinitionRepository;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * WarmupDynamicToolsCacheCommand - Lädt genehmigte Tools aus der DB und cached sie für den CompilerPass.
 * 
 * Da der CompilerPass zur Compile-Time läuft, können wir nicht direkt auf die Datenbank zugreifen.
 * Dieser Command lädt die Tools und speichert sie im Cache, damit der CompilerPass sie verwenden kann.
 * 
 * @see https://symfony.com/doc/current/components/cache.html
 */
#[AsCommand(
    name: 'evie:tools:warmup-cache',
    description: 'Lädt genehmigte Tools aus der DB und cached sie für den CompilerPass.'
)]
final class WarmupDynamicToolsCacheCommand extends Command
{

    private const CACHE_KEY = 'evie.dynamic_tools.approved';
    private const CACHE_TTL = 3600; // 1 Stunde

    public function __construct(
        private ToolDefinitionRepository $toolDefinitionRepo,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Lädt genehmigte Tools aus der DB und cached sie für den CompilerPass.')
            ->setHelp('Dieser Command wird beim Deployment ausgeführt, um Tools für den AiDynamicToolsCompilerPass verfügbar zu machen.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>⏳ Lade genehmigte Tools aus der Datenbank...</info>');

        // Lade alle genehmigten Tools
        $approvedTools = $this->toolDefinitionRepo->findBy([
            'status' => ['approved'],
        ]);

        $output->writeln(sprintf('<info>✅ %d genehmigte Tools gefunden</info>', count($approvedTools)));

        // Erstelle Cache-Adapter
        $cache = new FilesystemAdapter('evie_dynamic_tools', 0, '%kernel.cache_dir%');

        // Speichere Tools im Cache
        $cacheItem = $cache->getItem(self::CACHE_KEY);
        
        // Speichere Tool-IDs (für CompilerPass)
        $toolIds = array_map(function($tool) {
            return $tool->getId();
        }, $approvedTools);
        
        $cacheItem->set($toolIds);
        $cacheItem->expiresAfter(self::CACHE_TTL);
        $cache->save($cacheItem);

        $output->writeln(sprintf('<info>✅ Cache für %d Tools gewärmt (TTL: %d Sekunden)</info>', count($toolIds), self::CACHE_TTL));
        $output->writeln('<info>💡 Führe nun den CompilerPass aus: bin/console cache:clear</info>');

        return Command::SUCCESS;
    }
}
