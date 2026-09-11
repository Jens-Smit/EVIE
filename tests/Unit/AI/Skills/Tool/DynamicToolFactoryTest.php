<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Skills\Tool;

use App\AI\Skills\SubAgentDefinitionLoader;
use App\AI\Skills\SubAgentPromptResolver;
use App\AI\Skills\SubAgentRegistry;
use App\AI\Skills\SubAgentToolFactory;
use App\AI\Skills\Tool\DynamicTool;
use App\AI\Skills\Tool\DynamicToolFactory;
use App\Entity\ToolDefinition;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit-Tests fuer DynamicToolFactory.
 */
final class DynamicToolFactoryTest extends TestCase
{
    private SubAgentDefinitionLoader $definitionLoader;
    private SubAgentToolFactory $toolFactory;
    private SubAgentRegistry $registry;
    private SubAgentPromptResolver $promptResolver;
    private LoggerInterface $logger;
    private DynamicToolFactory $factory;

    protected function setUp(): void
    {
        $this->definitionLoader = $this->createMock(SubAgentDefinitionLoader::class);
        $this->toolFactory = $this->createMock(SubAgentToolFactory::class);
        $this->registry = $this->createMock(SubAgentRegistry::class);
        $this->promptResolver = $this->createMock(SubAgentPromptResolver::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->factory = new DynamicToolFactory(
            $this->definitionLoader,
            $this->toolFactory,
            $this->registry,
            $this->promptResolver,
            $this->logger,
        );
    }

    private function makeDefinition(): ToolDefinition
    {
        $def = new ToolDefinition();
        $def->setName('test-tool');
        $def->setDescription('desc');

        return $def;
    }

    public function testCreateAndRegisterToolReturnsToolAndRegisters(): void
    {
        $definition = $this->makeDefinition();
        $tool = $this->createMock(DynamicTool::class);

        $this->toolFactory->expects(self::once())
            ->method('createFromDefinition')
            ->with($definition)
            ->willReturn($tool);
        $this->registry->expects(self::once())
            ->method('registerTool')
            ->with($tool, $definition);

        $result = $this->factory->createAndRegisterTool($definition);

        self::assertSame($tool, $result);
    }

    public function testCreateFromDefinitionIdReturnsNullWhenNotFound(): void
    {
        $this->definitionLoader->method('loadFromDatabase')->willReturn(null);
        $this->logger->expects(self::once())->method('warning');

        $result = $this->factory->createFromDefinitionId(42);

        self::assertNull($result);
    }

    public function testCreateFromDefinitionIdReturnsToolWhenFound(): void
    {
        $definition = $this->makeDefinition();
        $tool = $this->createMock(DynamicTool::class);

        $this->definitionLoader->method('loadFromDatabase')->willReturn($definition);
        $this->toolFactory->method('createFromDefinition')->willReturn($tool);
        $this->registry->expects(self::once())->method('registerTool');

        $result = $this->factory->createFromDefinitionId(42);

        self::assertSame($tool, $result);
    }

    public function testLoadAndRegisterAllApprovedDelegatesToRegistry(): void
    {
        $this->registry->expects(self::once())->method('loadAndRegisterApprovedTools');
        $this->factory->loadAndRegisterAllApproved();
    }

    public function testGetToolPromptDelegatesToPromptResolver(): void
    {
        $definition = $this->makeDefinition();
        $this->promptResolver->expects(self::once())
            ->method('createToolPrompt')
            ->with($definition)
            ->willReturn('prompt text');

        self::assertSame('prompt text', $this->factory->getToolPrompt($definition));
    }

    public function testRemoveToolDelegatesToRegistry(): void
    {
        $this->registry->expects(self::once())->method('unregisterTool')->with('name');
        $this->factory->removeTool('name');
    }

    public function testReloadDelegatesToRegistry(): void
    {
        $this->registry->expects(self::once())->method('reload');
        $this->factory->reload();
    }

    public function testGetToolDelegatesToRegistry(): void
    {
        $tool = $this->createMock(DynamicTool::class);
        $this->registry->method('getTool')->with('name')->willReturn($tool);
        self::assertSame($tool, $this->factory->getTool('name'));
    }

    public function testGetAllToolsDelegatesToRegistry(): void
    {
        $tools = ['a' => $this->createMock(DynamicTool::class)];
        $this->registry->method('getAllTools')->willReturn($tools);
        self::assertSame($tools, $this->factory->getAllTools());
    }

    public function testHasToolDelegatesToRegistry(): void
    {
        $this->registry->method('hasTool')->with('name')->willReturn(true);
        self::assertTrue($this->factory->hasTool('name'));
    }
}
