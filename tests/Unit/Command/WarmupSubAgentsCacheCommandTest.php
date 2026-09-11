<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\WarmupSubAgentsCacheCommand;
use App\Entity\SubAgentDefinition;
use App\Repository\SubAgentDefinitionRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Console\Tester\CommandTester;

final class WarmupSubAgentsCacheCommandTest extends TestCase
{
    private SubAgentDefinitionRepository&MockObject $repo;
    private CacheItemPoolInterface&MockObject $cache;
    private WarmupSubAgentsCacheCommand $command;

    protected function setUp(): void
    {
        $this->repo = $this->createMock(SubAgentDefinitionRepository::class);
        $this->cache = $this->createMock(CacheItemPoolInterface::class);
        $this->command = new WarmupSubAgentsCacheCommand($this->repo, $this->cache);
    }

    public function testExecuteWarnsWhenNoDefinitionsFound(): void
    {
        $this->repo->method('findAllActive')->willReturn([]);
        $tester = new CommandTester($this->command);

        $exit = $tester->execute([]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('Keine aktiven Sub-Agenten', $tester->getDisplay());
    }

    public function testExecuteCachesAndListsDefinitions(): void
    {
        $def1 = (new SubAgentDefinition())
            ->setName('Researcher')
            ->setDescription('d')
            ->setClassName('App\AI\Agent\Researcher')
            ->setConfiguration([])
            ->setIsActive(true);

        $def2 = (new SubAgentDefinition())
            ->setName('Writer')
            ->setDescription('d')
            ->setClassName('App\AI\Agent\Writer')
            ->setConfiguration([])
            ->setIsActive(false);

        $this->repo->method('findAllActive')->willReturn([$def1, $def2]);

        $item = $this->createMock(CacheItemInterface::class);
        $item->expects(self::once())->method('set')->with([$def1, $def2])->willReturnSelf();
        $this->cache->expects(self::once())->method('getItem')->with('ai.sub_agent.definitions')->willReturn($item);
        $this->cache->expects(self::once())->method('save')->with($item)->willReturn(true);

        $tester = new CommandTester($this->command);
        $exit = $tester->execute([]);

        self::assertSame(0, $exit);
        $display = $tester->getDisplay();
        self::assertStringContainsString('Researcher', $display);
        self::assertStringContainsString('Writer', $display);
        self::assertStringContainsString('Ja', $display);
        self::assertStringContainsString('Nein', $display);
        self::assertStringContainsString('2 Sub-Agenten-Definitionen', $display);
    }
}
