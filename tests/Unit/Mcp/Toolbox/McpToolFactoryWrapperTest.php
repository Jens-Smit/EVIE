<?php

declare(strict_types=1);

// tests/Unit/Mcp/Toolbox/McpToolFactoryWrapperTest.php

namespace App\Tests\Unit\Mcp\Toolbox;

use App\Mcp\Toolbox\McpRemoteToolMetadata;
use App\Mcp\Toolbox\McpToolFactory;
use App\Mcp\Toolbox\McpToolFactoryWrapper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class McpToolFactoryWrapperTest extends TestCase
{
    private McpToolFactory&MockObject $factory;

    protected function setUp(): void
    {
        $this->factory = $this->createMock(McpToolFactory::class);
    }

    private function metadata(string $name): McpRemoteToolMetadata
    {
        return new McpRemoteToolMetadata($name, 'desc', ['type' => 'object'], 'github', 'search_repos');
    }

    public function testGetToolYieldsOnlyMatchingToolWhenNameMatches(): void
    {
        $matching = $this->metadata('github_search_repos');
        $other = $this->metadata('github_list_files');

        $this->factory->method('getTools')->willReturnCallback(
            function () use ($matching, $other): \Generator {
                yield $matching;
                yield $other;
            }
        );

        $wrapper = new McpToolFactoryWrapper($this->factory);
        $tools = iterator_to_array($wrapper->getTool('github_search_repos'), false);

        self::assertCount(1, $tools);
        self::assertSame('github_search_repos', $tools[0]->getName());
    }

    public function testGetToolYieldsAllToolsWhenNameDoesNotMatch(): void
    {
        $first = $this->metadata('github_search_repos');
        $second = $this->metadata('github_list_files');

        $this->factory->method('getTools')->willReturnCallback(
            function () use ($first, $second): \Generator {
                yield $first;
                yield $second;
            }
        );

        $wrapper = new McpToolFactoryWrapper($this->factory);
        $tools = iterator_to_array($wrapper->getTool('github_unknown_tool'), false);

        self::assertCount(2, $tools);
    }

    public function testGetToolWithObjectReferenceYieldsAllTools(): void
    {
        $tool = $this->metadata('github_search_repos');
        $this->factory->method('getTools')->willReturnCallback(
            function () use ($tool): \Generator {
                yield $tool;
            }
        );

        $wrapper = new McpToolFactoryWrapper($this->factory);
        $tools = iterator_to_array($wrapper->getTool($this), false);

        self::assertCount(1, $tools);
        self::assertSame('github_search_repos', $tools[0]->getName());
    }
}
