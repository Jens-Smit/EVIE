<?php

declare(strict_types=1);

// tests/Unit/Mcp/Toolbox/McpToolFactoryTest.php

namespace App\Tests\Unit\Mcp\Toolbox;

use App\Mcp\Client\McpServerManager;
use App\Mcp\Toolbox\McpRemoteToolMetadata;
use App\Mcp\Toolbox\McpToolFactory;
use App\Mcp\Toolbox\McpToolFactoryWrapper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Cache\CacheInterface;

final class McpToolFactoryTest extends TestCase
{
    private McpServerManager&MockObject $serverManager;
    private CacheInterface&MockObject $cache;

    protected function setUp(): void
    {
        $this->serverManager = $this->createMock(McpServerManager::class);
        $this->cache = $this->createMock(CacheInterface::class);
    }

    /**
     * Der CacheCallback darf nicht selbst aufgerufen werden — die Tools
     * muessen aus dem Cache kommen, nicht vom (externen) Server.
     */
    public function testGetToolsReadsToolsFromCache(): void
    {
        $tools = [
            'search_repos' => [
                'name' => 'search_repos',
                'description' => 'Sucht Repos',
                'inputSchema' => ['type' => 'object', 'properties' => []],
            ],
        ];

        $this->cache
            ->expects(self::once())
            ->method('get')
            ->with('mcp_tools_github')
            ->willReturn($tools);

        $factory = new McpToolFactory($this->serverManager, $this->cache, ['github']);
        $result = iterator_to_array($factory->getTools(), false);

        self::assertCount(1, $result);
        self::assertInstanceOf(McpRemoteToolMetadata::class, $result[0]);
        self::assertSame('github_search_repos', $result[0]->getName());
        self::assertSame('Sucht Repos', $result[0]->getDescription());
        self::assertSame('github', $result[0]->getServerAlias());
        self::assertSame('search_repos', $result[0]->getRemoteName());
    }

    public function testGetToolsIncludesFrontendApprovedServers(): void
    {
        // Frontend-Freigabe: Der Manager kennt zusaetzlich einen aktiven
        // McpServerDefinition-Server, der nicht in der statischen Liste steht.
        $tools = [
            'fetch_imprint' => [
                'name' => 'fetch_imprint',
                'description' => 'Extrahiert Impressum',
                'inputSchema' => ['type' => 'object', 'properties' => []],
            ],
        ];

        $this->cache
            ->method('get')
            ->with('mcp_tools_website_researcher')
            ->willReturn($tools);
        $this->serverManager
            ->method('getAvailableServerAliases')
            ->willReturn(['website_researcher']);

        $factory = new McpToolFactory($this->serverManager, $this->cache, ['github']);
        $result = iterator_to_array($factory->getTools(), false);

        self::assertCount(1, $result);
        self::assertSame('website_researcher_fetch_imprint', $result[0]->getName());
        self::assertSame('website_researcher', $result[0]->getServerAlias());
    }

    public function testGetToolsWithoutAliasesYieldsNothing(): void
    {
        $this->cache->expects(self::never())->method('get');

        $factory = new McpToolFactory($this->serverManager, $this->cache, []);
        self::assertSame([], iterator_to_array($factory->getTools(), false));
    }
}
