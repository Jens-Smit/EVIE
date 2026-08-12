<?php

namespace App\Tests\Unit\AI\Skills\Tool;

use App\AI\Skills\Tool\DynamicToolFactory;
use App\Entity\ToolDefinition;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Unit-Tests für DynamicToolFactory
 * 
 * Testet:
 * - Tool-Erstellung aus ToolDefinition
 * - SecurityGuard-Integration
 * - Sub-Agenten-Unterstützung
 * - Fehlerbehandlung
 */
final class DynamicToolFactoryTest extends TestCase
{
    private DynamicToolFactory $factory;
    private ContainerInterface $container;

    protected function setUp(): void
    {
        $this->container = $this->createMock(ContainerInterface::class);
        
        $toolDefinitionRepo = $this->createMock(\App\Repository\ToolDefinitionRepository::class);
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $subAgentFactory = $this->createMock(\App\AI\Agent\SubAgentFactory::class);
        $dynamicSkillRegistry = $this->createMock(\App\AI\Skills\DynamicSkillRegistry::class);
        $securityGuard = $this->createMock(\App\AI\Security\SecurityGuard::class);

        $this->factory = new DynamicToolFactory(
            $this->container,
            $toolDefinitionRepo,
            $logger,
            $subAgentFactory,
            $dynamicSkillRegistry,
            $securityGuard
        );
    }

    // ========================================================================
    // Helper-Methoden
    // ========================================================================

    private function createToolDefinition(
        string $name = 'test_tool',
        string $status = 'approved',
        array $schema = []
    ): ToolDefinition {
        $toolDefinition = new ToolDefinition();
        $toolDefinition->setName($name);
        $toolDefinition->setDescription('Test Tool Description');
        $toolDefinition->setStatus($status);
        $toolDefinition->setSchema($schema);
        $toolDefinition->setParameters([
            ['name' => 'input', 'type' => 'string', 'required' => true],
        ]);
        return $toolDefinition;
    }

    // ========================================================================
    // Tests für createTool()
    // ========================================================================

    public function testCreateToolWithApprovedTool(): void
    {
        $toolDefinition = $this->createToolDefinition(
            'approved_tool',
            'approved',
            ['service' => 'App\AI\Skills\Tool\GenericApiExecutor']
        );

        // Mock DynamicToolExecutor
        $executor = $this->createMock(\App\AI\Skills\Tool\DynamicToolExecutor::class);
        $this->container->method('has')->with('App\AI\Skills\Tool\DynamicToolExecutor')->willReturn(true);
        $this->container->method('get')->with('App\AI\Skills\Tool\DynamicToolExecutor')->willReturn($executor);

        $tool = $this->factory->createTool($toolDefinition);

        $this->assertInstanceOf(\App\AI\Skills\Tool\DynamicTool::class, $tool);
        $this->assertEquals('approved_tool', $tool->getName());
        $this->assertEquals('Test Tool Description', $tool->getDescription());
    }

    public function testCreateToolWithPendingToolThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ist nicht genehmigt');

        $toolDefinition = $this->createToolDefinition('pending_tool', 'pending');
        $this->factory->createTool($toolDefinition);
    }

    public function testCreateToolWithBlockedServiceThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ist nicht in der SecurityGuard-Whitelist enthalten');

        $toolDefinition = $this->createToolDefinition(
            'blocked_tool',
            'approved',
            ['service' => 'App\AI\Skills\Tool\DangerousExecutor']
        );

        // SecurityGuard wirft Exception
        $securityGuard = $this->createMock(\App\AI\Security\SecurityGuard::class);
        $securityGuard->method('assertToolAllowed')->willThrowException(new \RuntimeException('ist nicht in der SecurityGuard-Whitelist enthalten'));

        // Erstelle Factory mit mock SecurityGuard
        $factory = new DynamicToolFactory(
            $this->container,
            $this->createMock(\App\Repository\ToolDefinitionRepository::class),
            $this->createMock(\Psr\Log\LoggerInterface::class),
            $this->createMock(\App\AI\Agent\SubAgentFactory::class),
            $this->createMock(\App\AI\Skills\DynamicSkillRegistry::class),
            $securityGuard
        );

        $factory->createTool($toolDefinition);
    }

    public function testCreateToolWithWildcardService(): void
    {
        $toolDefinition = $this->createToolDefinition(
            'wildcard_tool',
            'approved',
            ['service' => 'App\AI\Skills\Tool\CustomTool']
        );

        $executor = $this->createMock(\App\AI\Skills\Tool\DynamicToolExecutor::class);
        $this->container->method('has')->willReturn(true);
        $this->container->method('get')->willReturn($executor);

        $tool = $this->factory->createTool($toolDefinition);
        $this->assertInstanceOf(\App\AI\Skills\Tool\DynamicTool::class, $tool);
    }

    // ========================================================================
    // Tests für createTools()
    // ========================================================================

    public function testCreateToolsWithMultipleTools(): void
    {
        $tool1 = $this->createToolDefinition('tool1', 'approved', ['service' => 'App\AI\Skills\Tool\GenericApiExecutor']);
        $tool2 = $this->createToolDefinition('tool2', 'approved', ['service' => 'App\AI\Skills\Tool\FileSystemReadExecutor']);

        $executor = $this->createMock(\App\AI\Skills\Tool\DynamicToolExecutor::class);
        $this->container->method('has')->willReturn(true);
        $this->container->method('get')->willReturn($executor);

        $tools = $this->factory->createTools([$tool1, $tool2]);

        $this->assertCount(2, $tools);
        $this->assertInstanceOf(\App\AI\Skills\Tool\DynamicTool::class, $tools[0]);
        $this->assertInstanceOf(\App\AI\Skills\Tool\DynamicTool::class, $tools[1]);
    }

    public function testCreateToolsWithMixedStatus(): void
    {
        $tool1 = $this->createToolDefinition('tool1', 'approved', ['service' => 'App\AI\Skills\Tool\GenericApiExecutor']);
        $tool2 = $this->createToolDefinition('tool2', 'pending', []);

        $executor = $this->createMock(\App\AI\Skills\Tool\DynamicToolExecutor::class);
        $this->container->method('has')->willReturn(true);
        $this->container->method('get')->willReturn($executor);

        $tools = $this->factory->createTools([$tool1, $tool2]);

        // Nur das genehmigte Tool sollte erstellt werden
        $this->assertCount(1, $tools);
        $this->assertEquals('tool1', $tools[0]->getName());
    }

    // ========================================================================
    // Tests für canCreateTool()
    // ========================================================================

    public function testCanCreateToolWithApprovedAndAllowedTool(): void
    {
        $toolDefinition = $this->createToolDefinition(
            'valid_tool',
            'approved',
            ['service' => 'App\AI\Skills\Tool\GenericApiExecutor']
        );

        $this->assertTrue($this->factory->canCreateTool($toolDefinition));
    }

    public function testCanCreateToolWithPendingTool(): void
    {
        $toolDefinition = $this->createToolDefinition('pending_tool', 'pending');
        $this->assertFalse($this->factory->canCreateTool($toolDefinition));
    }

    public function testCanCreateToolWithBlockedService(): void
    {
        $toolDefinition = $this->createToolDefinition(
            'blocked_tool',
            'approved',
            ['service' => 'App\AI\Skills\Tool\DangerousExecutor']
        );

        // SecurityGuard wirft Exception
        $securityGuard = $this->createMock(\App\AI\Security\SecurityGuard::class);
        $securityGuard->method('assertToolAllowed')->willThrowException(new \RuntimeException());

        $factory = new DynamicToolFactory(
            $this->container,
            $this->createMock(\App\Repository\ToolDefinitionRepository::class),
            $this->createMock(\Psr\Log\LoggerInterface::class),
            $this->createMock(\App\AI\Agent\SubAgentFactory::class),
            $this->createMock(\App\AI\Skills\DynamicSkillRegistry::class),
            $securityGuard
        );

        $this->assertFalse($factory->canCreateTool($toolDefinition));
    }

    // ========================================================================
    // Tests für Sub-Agenten-Unterstützung
    // ========================================================================

    public function testCreateToolForDefinitionWithSubAgent(): void
    {
        $toolDefinition = $this->createToolDefinition('subagent_tool', 'approved');
        
        // Füge Sub-Agenten-Parameter hinzu
        $toolDefinition->setParameters([
            ['name' => 'sub_agent', 'type' => 'string', 'value' => 'website_researcher'],
        ]);

        $tool = $this->factory->createToolForDefinition($toolDefinition);

        $this->assertInstanceOf(\App\AI\Skills\Tool\ToolInterface::class, $tool);
        $this->assertEquals('subagent_tool', $tool->getName());
    }

    // ========================================================================
    // Edge Cases
    // ========================================================================

    public function testCreateToolWithEmptySchema(): void
    {
        $toolDefinition = $this->createToolDefinition('empty_schema_tool', 'approved', []);

        $executor = $this->createMock(\App\AI\Skills\Tool\DynamicToolExecutor::class);
        $this->container->method('has')->willReturn(true);
        $this->container->method('get')->willReturn($executor);

        $tool = $this->factory->createTool($toolDefinition);
        $this->assertInstanceOf(\App\AI\Skills\Tool\DynamicTool::class, $tool);
    }

    public function testCreateToolWithComplexSchema(): void
    {
        $toolDefinition = $this->createToolDefinition(
            'complex_tool',
            'approved',
            [
                'type' => 'object',
                'properties' => [
                    'input' => ['type' => 'string'],
                    'count' => ['type' => 'integer'],
                ],
                'required' => ['input'],
            ]
        );

        $executor = $this->createMock(\App\AI\Skills\Tool\DynamicToolExecutor::class);
        $this->container->method('has')->willReturn(true);
        $this->container->method('get')->willReturn($executor);

        $tool = $this->factory->createTool($toolDefinition);
        $this->assertInstanceOf(\App\AI\Skills\Tool\DynamicTool::class, $tool);
        $this->assertEquals('complex_tool', $tool->getName());
    }
}
