<?php

declare(strict_types=1);

// tests/Unit/Command/WarmupMcpServersCacheCommandTest.php

namespace App\Tests\Unit\Command;

use App\Command\WarmupMcpServersCacheCommand;
use App\Entity\McpServerDefinition;
use App\Repository\McpServerDefinitionRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Component\Console\Tester\CommandTester;

final class WarmupMcpServersCacheCommandTest extends TestCase
{
    private McpServerDefinitionRepository&MockObject $repo;
    private CacheInterface&MockObject $cache;
    private WarmupMcpServersCacheCommand $command;

    protected function setUp(): void
    {
        $this->repo = $this->createMock(McpServerDefinitionRepository::class);
        $this->cache = $this->createMock(CacheInterface::class);
        $this->command = new WarmupMcpServersCacheCommand($this->repo, $this->cache);
    }

    public function testExecuteWarnsWhenNoActiveServers(): void
    {
        $this->repo->method('findAllActive')->willReturn([]);
        $this->cache->expects(self::never())->method('get');

        $tester = new CommandTester($this->command);
        $exit = $tester->execute([]);
        self::assertSame(0, $exit);
        self::assertStringContainsString('Keine aktiven MCP-Server', $tester->getDisplay());
    }

    public function testExecuteCachesDefinitionsAndOutputsDetails(): void
    {
        $definition = new McpServerDefinition();
        $definition->setName('test_filesystem');
        $definition->setType('filesystem');
        $definition->setDescription('Test Filesystem Server');
        $definition->setIsActive(true);
        $definition->setAllowedTools(['read_file', 'list_files']);
        $definition->setBlockedResources(['/etc/*']);

        $this->repo->method('findAllActive')->willReturn([$definition]);
        $this->cache
            ->expects(self::once())
            ->method('get')
            ->with('ai.mcp_server.definitions', self::callback(fn (): bool => true))
            ->willReturn([$definition]);

        $tester = new CommandTester($this->command);
        $exit = $tester->execute([]);
        self::assertSame(0, $exit);

        $display = $tester->getDisplay();
        self::assertStringContainsString('1 MCP-Server-Definitionen wurden geladen und gecacht.', $display);
        self::assertStringContainsString('test_filesystem', $display);
        self::assertStringContainsString('read_file, list_files', $display);
        self::assertStringContainsString('/etc/*', $display);
    }
}
