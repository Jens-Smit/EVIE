<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Skills;

use App\AI\Skills\SubAgentDefinitionLoader;
use App\AI\Skills\SubAgentToolFactory;
use App\AI\Skills\Tool\DynamicTool;
use App\Entity\ToolDefinition;
use PHPUnit\Framework\TestCase;

/**
 * Vollstaendige Test-Abdeckung fuer SubAgentToolFactory.
 */
final class SubAgentToolFactoryTest extends TestCase
{
    private function createFactory(): SubAgentToolFactory
    {
        return new SubAgentToolFactory($this->createMock(SubAgentDefinitionLoader::class));
    }

    private function createDefinition(): ToolDefinition
    {
        $def = new ToolDefinition();
        $def->setName('test-tool');
        $def->setDescription('A test tool');
        $def->setSchema(['type' => 'object']);
        $def->setExecutorType('http');
        $def->setExecutorConfig(['url' => 'https://example.com']);
        $def->setSecurityPolicy(['require_approval' => true]);
        $def->setHitlPolicy(['level' => 'high']);
        $def->setVersion('2.1');
        return $def;
    }

    public function testCreateFromDefinitionWithFullData(): void
    {
        $def = $this->createDefinition();
        $tool = $this->createFactory()->createFromDefinition($def);

        self::assertInstanceOf(DynamicTool::class, $tool);
        self::assertSame('test-tool', $tool->getName());
    }

    public function testCreateFromDefinitionWithNullDefaults(): void
    {
        $def = new ToolDefinition();
        $def->setName('minimal');
        // executorConfig, securityPolicy, hitlPolicy, version all null
        $tool = $this->createFactory()->createFromDefinition($def);

        self::assertInstanceOf(DynamicTool::class, $tool);
        self::assertSame('minimal', $tool->getName());
    }

    public function testCreateMultipleFromDefinitions(): void
    {
        $def1 = new ToolDefinition();
        $def1->setName('tool-1');
        $def2 = new ToolDefinition();
        $def2->setName('tool-2');
        $def3 = new ToolDefinition();
        $def3->setName('tool-3');

        $tools = $this->createFactory()->createMultipleFromDefinitions([$def1, $def2, $def3]);

        self::assertCount(3, $tools);
        self::assertInstanceOf(DynamicTool::class, $tools[0]);
        self::assertSame('tool-1', $tools[0]->getName());
        self::assertSame('tool-2', $tools[1]->getName());
        self::assertSame('tool-3', $tools[2]->getName());
    }

    public function testCreateMultipleFromDefinitionsEmptyArray(): void
    {
        $tools = $this->createFactory()->createMultipleFromDefinitions([]);
        self::assertSame([], $tools);
    }

    public function testCreateFromDefinitionPassesAllConfigFields(): void
    {
        $def = $this->createDefinition();
        $tool = $this->createFactory()->createFromDefinition($def);

        self::assertSame('test-tool', $tool->getName());
        self::assertSame('A test tool', $tool->getDescription());
    }
}
