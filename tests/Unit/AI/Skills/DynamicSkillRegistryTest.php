<?php

namespace App\Tests\Unit\AI\Skills;

use App\AI\Skills\DynamicSkillRegistry;
use App\Entity\ToolDefinition;
use App\Repository\ToolDefinitionRepository;
use PHPUnit\Framework\TestCase;

class DynamicSkillRegistryTest extends TestCase
{
    private DynamicSkillRegistry $registry;
    private ToolDefinitionRepository $repo;

    protected function setUp(): void
    {
        $this->repo = $this->createMock(ToolDefinitionRepository::class);
        $this->registry = new DynamicSkillRegistry($this->repo);
    }

    public function testLoadTools(): void
    {
        $toolDefinition = new ToolDefinition();
        $toolDefinition->setName('TestTool');
        $toolDefinition->setStatus('approved');
        $toolDefinition->setSchema(['type' => 'object']);

        $this->repo->method('findBy')
            ->with(['status' => 'approved'])
            ->willReturn([$toolDefinition]);

        $this->registry->loadTools();
        $tools = $this->registry->getAvailableTools();

        $this->assertArrayHasKey('TestTool', $tools);
    }

    public function testGetTool(): void
    {
        $toolDefinition = new ToolDefinition();
        $toolDefinition->setName('TestTool');
        $toolDefinition->setStatus('approved');
        $toolDefinition->setSchema(['type' => 'object']);

        $this->repo->method('findBy')
            ->with(['status' => 'approved'])
            ->willReturn([$toolDefinition]);

        $this->registry->loadTools();
        $tool = $this->registry->getTool('TestTool');

        $this->assertNotNull($tool);
    }

    public function testGetToolNotFound(): void
    {
        $this->repo->method('findBy')
            ->with(['status' => 'approved'])
            ->willReturn([]);

        $this->registry->loadTools();

        $this->expectException(\InvalidArgumentException::class);
        $this->registry->getTool('NonExistentTool');
    }

    public function testAddTool(): void
    {
        $toolDefinition = new ToolDefinition();
        $toolDefinition->setName('NewTool');
        $toolDefinition->setStatus('approved');
        $toolDefinition->setSchema(['type' => 'object']);

        $this->registry->addTool($toolDefinition);
        $tools = $this->registry->getAvailableTools();

        $this->assertArrayHasKey('NewTool', $tools);
    }

    public function testRemoveTool(): void
    {
        $toolDefinition = new ToolDefinition();
        $toolDefinition->setName('TestTool');
        $toolDefinition->setStatus('approved');
        $toolDefinition->setSchema(['type' => 'object']);

        $this->repo->method('findBy')
            ->with(['status' => 'approved'])
            ->willReturn([$toolDefinition]);

        $this->registry->loadTools();
        $this->registry->removeTool('TestTool');
        $tools = $this->registry->getAvailableTools();

        $this->assertArrayNotHasKey('TestTool', $tools);
    }
}
