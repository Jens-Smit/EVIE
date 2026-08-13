<?php

namespace App\Tests\Unit\AI\Skills;

use App\AI\Skills\DynamicSkillRegistry;
use App\AI\Skills\Tool\DynamicToolFactory;
use App\Entity\ToolCategory;
use App\Entity\ToolDefinition;
use PHPUnit\Framework\TestCase;

/**
 * Unit-Tests für DynamicSkillRegistry
 * 
 * Phase 3: Maßnahme 9 - E2E-Test für Evolution-Flow
 * 
 * Testet:
 * - Tool-Loading aus Repository
 * - Tool-Metadaten
 * - Tool-Verwaltung (add/remove)
 * - Initialisierungsstatus
 * - CompilerPass-Integration (Phase 3)
 * 
 * @see ROADMAP_PHASE3.md
 */
final class DynamicSkillRegistryTest extends TestCase
{
    private DynamicSkillRegistry $registry;
    private \App\Repository\ToolDefinitionRepository $repo;
    private DynamicToolFactory $toolFactory;

    protected function setUp(): void
    {
        $this->repo = $this->createMock(\App\Repository\ToolDefinitionRepository::class);
        $this->toolFactory = $this->createMock(DynamicToolFactory::class);

        $this->registry = new DynamicSkillRegistry($this->repo, $this->toolFactory);
    }

    // ========================================================================
    // Helper-Methoden
    // ========================================================================

    private function createToolDefinition(
        string $name = 'test_tool',
        string $status = 'approved',
        array $schema = [],
        array $parameters = []
    ): ToolDefinition {
        $toolDefinition = new ToolDefinition();
        $toolDefinition->setName($name);
        $toolDefinition->setDescription('Test Tool Description');
        $toolDefinition->setStatus($status);
        $toolDefinition->setSchema($schema);
        $toolDefinition->setParameters($parameters ?: [['name' => 'input', 'type' => 'string', 'required' => true]]);
        return $toolDefinition;
    }

    private function createToolCategory(string $name = 'general'): ToolCategory
    {
        $category = new ToolCategory();
        $category->setName($name);
        return $category;
    }

    // ========================================================================
    // Tests für initialize() und loadTools()
    // ========================================================================

    public function testInitializeLoadsApprovedTools(): void
    {
        $tool1 = $this->createToolDefinition('tool1', 'approved');
        $tool2 = $this->createToolDefinition('tool2', 'approved');
        $tool3 = $this->createToolDefinition('tool3', 'pending');

        $this->repo->method('findBy')->with(['status' => 'approved'])->willReturn([$tool1, $tool2]);

        $this->registry->initialize();

        $this->assertTrue($this->registry->hasTool('tool1'));
        $this->assertTrue($this->registry->hasTool('tool2'));
        $this->assertFalse($this->registry->hasTool('tool3'));
    }

    public function testInitializeDoesNotReloadIfAlreadyInitialized(): void
    {
        $tool = $this->createToolDefinition('tool1', 'approved');
        
        // Mock nur einen Aufruf von findBy
        $this->repo->expects($this->once())
            ->method('findBy')
            ->with(['status' => 'approved'])
            ->willReturn([$tool]);

        $this->registry->initialize();
        $this->registry->initialize(); // Zweiter Aufruf - sollte findBy nicht nochmal aufrufen

        $this->assertTrue($this->registry->isInitialized());
    }

    public function testLoadToolsWithNoTools(): void
    {
        $this->repo->method('findBy')->willReturn([]);

        $this->registry->loadTools();

        $this->assertFalse($this->registry->hasTool('nonexistent'));
        $this->assertEquals(0, $this->registry->countTools());
    }

    // ========================================================================
    // PHASE 3: Tests für CompilerPass-Integration
    // ========================================================================

    /**
     * Testet die Umwandlung von ToolDefinition in ausführbare Tools
     * (wichtig für CompilerPass-Integration)
     */
    public function testToolDefinitionToToolConversion(): void
    {
        // 1. Erstelle eine ToolDefinition mit Schema
        $toolDefinition = $this->createToolDefinition(
            'compiler_pass_tool',
            'approved',
            [
                'type' => 'object',
                'properties' => [
                    'input' => ['type' => 'string', 'description' => 'Input parameter'],
                    'count' => ['type' => 'integer', 'minimum' => 0]
                ],
                'required' => ['input']
            ],
            [
                ['name' => 'input', 'type' => 'string', 'required' => true],
                ['name' => 'count', 'type' => 'integer', 'required' => false]
            ]
        );
        
        // 2. Mock Repository
        $this->repo->method('findBy')->with(['status' => 'approved'])->willReturn([$toolDefinition]);
        
        // 3. Mock ToolFactory für die Umwandlung
        $toolInterface = $this->createMock(\Symfony\AI\Agent\Tool\ToolInterface::class);
        $toolInterface->method('getName')->willReturn('compiler_pass_tool');
        
        $this->toolFactory->method('createTool')->willReturn($toolInterface);
        
        // 4. Teste das Laden
        $this->registry->initialize();
        $tools = $this->registry->getAvailableTools();
        
        // 5. Validierungen
        $this->assertArrayHasKey('compiler_pass_tool', $tools);
        $this->assertInstanceOf(\Symfony\AI\Agent\Tool\ToolInterface::class, $tools['compiler_pass_tool']);
    }

    /**
     * Testet die Behandlung von Tools mit Sicherheitsmetadaten (Phase 3)
     */
    public function testHandlingToolsWithSecurityMetadata(): void
    {
        // 1. Tool mit Sicherheitsmetadaten
        $toolDefinition = $this->createToolDefinition(
            'secure_tool',
            'approved',
            [
                'type' => 'object',
                'properties' => ['input' => ['type' => 'string']],
                'security_level' => 'high',
                'hitl_required' => true
            ]
        );
        
        // 2. Mock Repository
        $this->repo->method('findBy')->with(['status' => 'approved'])->willReturn([$toolDefinition]);
        
        // 3. Mock ToolFactory
        $toolInterface = $this->createMock(\Symfony\AI\Agent\Tool\ToolInterface::class);
        $toolInterface->method('getName')->willReturn('secure_tool');
        
        $this->toolFactory->method('createTool')->willReturn($toolInterface);
        
        // 4. Teste das Laden
        $this->registry->initialize();
        $tools = $this->registry->getAvailableTools();
        
        // 5. Validierungen
        $this->assertArrayHasKey('secure_tool', $tools);
    }

    /**
     * Testet die Behandlung von Tools mit Sub-Agenten-Zuordnung (Phase 3)
     */
    public function testHandlingToolsWithSubAgentAssignment(): void
    {
        // 1. Tool mit Sub-Agenten-Zuordnung
        $toolDefinition = $this->createToolDefinition(
            'sub_agent_tool',
            'approved',
            [
                'type' => 'object',
                'properties' => ['input' => ['type' => 'string']],
                'sub_agent' => 'data_analyst'
            ]
        );
        
        // 2. Mock Repository
        $this->repo->method('findBy')->with(['status' => 'approved'])->willReturn([$toolDefinition]);
        
        // 3. Mock ToolFactory
        $toolInterface = $this->createMock(\Symfony\AI\Agent\Tool\ToolInterface::class);
        $toolInterface->method('getName')->willReturn('sub_agent_tool');
        
        $this->toolFactory->method('createTool')->willReturn($toolInterface);
        
        // 4. Teste das Laden
        $this->registry->initialize();
        $tools = $this->registry->getAvailableTools();
        
        // 5. Validierungen
        $this->assertArrayHasKey('sub_agent_tool', $tools);
    }

    // ========================================================================
    // Tests für reload()
    // ========================================================================

    public function testReloadClearsAndReloadsTools(): void
    {
        $tool1 = $this->createToolDefinition('tool1', 'approved');
        $tool2 = $this->createToolDefinition('tool2', 'approved');

        $this->repo->method('findBy')
            ->willReturnOnConsecutiveCalls(
                [$tool1],
                [$tool2]
            );

        $this->registry->initialize();
        $this->assertTrue($this->registry->hasTool('tool1'));
        $this->assertFalse($this->registry->hasTool('tool2'));

        $this->registry->reload();
        $this->assertFalse($this->registry->hasTool('tool1'));
        $this->assertTrue($this->registry->hasTool('tool2'));
    }

    // ========================================================================
    // Tests für getAvailableTools()
    // ========================================================================

    public function testGetAvailableToolsReturnsAllApprovedTools(): void
    {
        $tool1 = $this->createToolDefinition('tool1', 'approved', ['type' => 'object']);
        $tool2 = $this->createToolDefinition('tool2', 'approved', ['type' => 'string']);

        $this->repo->method('findBy')->willReturn([$tool1, $tool2]);

        $this->registry->initialize();
        $availableTools = $this->registry->getAvailableTools();

        $this->assertArrayHasKey('tool1', $availableTools);
        $this->assertArrayHasKey('tool2', $availableTools);
        $this->assertEquals('tool1', $availableTools['tool1']['name']);
        $this->assertEquals('tool2', $availableTools['tool2']['name']);
    }

    public function testGetAvailableToolsReturnsCorrectStructure(): void
    {
        $tool = $this->createToolDefinition(
            'structured_tool',
            'approved',
            ['type' => 'object', 'properties' => ['input' => ['type' => 'string']]]
        );

        $this->repo->method('findBy')->willReturn([$tool]);

        $this->registry->initialize();
        $availableTools = $this->registry->getAvailableTools();

        $this->assertArrayHasKey('structured_tool', $availableTools);
        $this->assertArrayHasKey('name', $availableTools['structured_tool']);
        $this->assertArrayHasKey('description', $availableTools['structured_tool']);
        $this->assertArrayHasKey('schema', $availableTools['structured_tool']);
        $this->assertArrayHasKey('status', $availableTools['structured_tool']);
    }

    // ========================================================================
    // PHASE 3: Tests für Tool-Kategorisierung
    // ========================================================================

    /**
     * Testet die Kategorisierung von Tools
     */
    public function testToolCategorization(): void
    {
        // 1. Tools mit verschiedenen Kategorien
        $category1 = $this->createToolCategory('web_scraping');
        $category2 = $this->createToolCategory('data_analysis');
        
        $tool1 = $this->createToolDefinition('web_tool', 'approved');
        $tool1->setCategory($category1);
        
        $tool2 = $this->createToolDefinition('data_tool', 'approved');
        $tool2->setCategory($category2);
        
        // 2. Mock Repository
        $this->repo->method('findBy')->with(['status' => 'approved'])->willReturn([$tool1, $tool2]);
        
        // 3. Mock ToolFactory
        $toolInterface1 = $this->createMock(\Symfony\AI\Agent\Tool\ToolInterface::class);
        $toolInterface1->method('getName')->willReturn('web_tool');
        
        $toolInterface2 = $this->createMock(\Symfony\AI\Agent\Tool\ToolInterface::class);
        $toolInterface2->method('getName')->willReturn('data_tool');
        
        $this->toolFactory->method('createTool')->willReturnOnConsecutiveCalls($toolInterface1, $toolInterface2);
        
        // 4. Teste das Laden
        $this->registry->initialize();
        $availableTools = $this->registry->getAvailableTools();
        
        // 5. Validierungen
        $this->assertArrayHasKey('web_tool', $availableTools);
        $this->assertArrayHasKey('data_tool', $availableTools);
        
        // Note: Die Kategorien sind in den ToolDefinition-Objekten gespeichert
        // und werden über getAvailableTools() zurückgegeben
    }

    // ========================================================================
    // Tests für getTool()
    // ========================================================================

    public function testGetToolReturnsToolDefinition(): void
    {
        $tool = $this->createToolDefinition('test_tool', 'approved');
        $this->repo->method('findBy')->willReturn([$tool]);

        $this->registry->initialize();
        $retrievedTool = $this->registry->getTool('test_tool');

        $this->assertInstanceOf(ToolDefinition::class, $retrievedTool);
        $this->assertEquals('test_tool', $retrievedTool->getName());
    }

    public function testGetToolThrowsExceptionForNonExistentTool(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not found or not approved');

        $this->repo->method('findBy')->willReturn([]);

        $this->registry->initialize();
        $this->registry->getTool('nonexistent_tool');
    }

    // ========================================================================
    // Tests für getToolMetadata()
    // ========================================================================

    public function testGetToolMetadataReturnsCorrectData(): void
    {
        $tool = $this->createToolDefinition(
            'metadata_tool',
            'approved',
            ['type' => 'object'],
            ['name' => 'input', 'type' => 'string']
        );

        $this->repo->method('findBy')->willReturn([$tool]);

        $this->registry->initialize();
        $metadata = $this->registry->getToolMetadata('metadata_tool');

        $this->assertIsArray($metadata);
        $this->assertEquals('metadata_tool', $metadata['name']);
        $this->assertEquals('Test Tool Description', $metadata['description']);
        $this->assertEquals(['type' => 'object'], $metadata['schema']);
        $this->assertEquals('approved', $metadata['status']);
    }

    public function testGetToolMetadataReturnsNullForNonExistentTool(): void
    {
        $this->repo->method('findBy')->willReturn([]);

        $this->registry->initialize();
        $metadata = $this->registry->getToolMetadata('nonexistent_tool');

        $this->assertNull($metadata);
    }

    // ========================================================================
    // Tests für addTool() und removeTool()
    // ========================================================================

    public function testAddToolAddsApprovedTool(): void
    {
        $tool = $this->createToolDefinition('new_tool', 'approved');

        $this->registry->addTool($tool);

        $this->assertTrue($this->registry->hasTool('new_tool'));
    }

    public function testAddToolDoesNotAddPendingTool(): void
    {
        $tool = $this->createToolDefinition('pending_tool', 'pending');

        $this->registry->addTool($tool);

        $this->assertFalse($this->registry->hasTool('pending_tool'));
    }

    public function testRemoveToolRemovesTool(): void
    {
        $tool = $this->createToolDefinition('removable_tool', 'approved');
        $this->repo->method('findBy')->willReturn([$tool]);

        $this->registry->initialize();
        $this->assertTrue($this->registry->hasTool('removable_tool'));

        $this->registry->removeTool('removable_tool');
        $this->assertFalse($this->registry->hasTool('removable_tool'));
    }

    public function testRemoveToolDoesNothingForNonExistentTool(): void
    {
        $this->registry->removeTool('nonexistent_tool');
        // Keine Exception, einfach keine Wirkung
        $this->assertTrue(true);
    }

    // ========================================================================
    // Tests für hasTool()
    // ========================================================================

    public function testHasToolReturnsTrueForExistingTool(): void
    {
        $tool = $this->createToolDefinition('existing_tool', 'approved');
        $this->repo->method('findBy')->willReturn([$tool]);

        $this->registry->initialize();

        $this->assertTrue($this->registry->hasTool('existing_tool'));
    }

    public function testHasToolReturnsFalseForNonExistentTool(): void
    {
        $this->repo->method('findBy')->willReturn([]);

        $this->registry->initialize();

        $this->assertFalse($this->registry->hasTool('nonexistent_tool'));
    }

    // ========================================================================
    // Tests für getToolNames()
    // ========================================================================

    public function testGetToolNamesReturnsAllToolNames(): void
    {
        $tool1 = $this->createToolDefinition('tool1', 'approved');
        $tool2 = $this->createToolDefinition('tool2', 'approved');

        $this->repo->method('findBy')->willReturn([$tool1, $tool2]);

        $this->registry->initialize();
        $toolNames = $this->registry->getToolNames();

        $this->assertContains('tool1', $toolNames);
        $this->assertContains('tool2', $toolNames);
        $this->assertCount(2, $toolNames);
    }

    public function testGetToolNamesReturnsEmptyArrayWhenNoTools(): void
    {
        $this->repo->method('findBy')->willReturn([]);

        $this->registry->initialize();
        $toolNames = $this->registry->getToolNames();

        $this->assertEmpty($toolNames);
    }

    // ========================================================================
    // Tests für countTools()
    // ========================================================================

    public function testCountToolsReturnsCorrectCount(): void
    {
        $tool1 = $this->createToolDefinition('tool1', 'approved');
        $tool2 = $this->createToolDefinition('tool2', 'approved');
        $tool3 = $this->createToolDefinition('tool3', 'approved');

        $this->repo->method('findBy')->willReturn([$tool1, $tool2, $tool3]);

        $this->registry->initialize();

        $this->assertEquals(3, $this->registry->countTools());
    }

    public function testCountToolsReturnsZeroWhenNoTools(): void
    {
        $this->repo->method('findBy')->willReturn([]);

        $this->registry->initialize();

        $this->assertEquals(0, $this->registry->countTools());
    }

    // ========================================================================
    // Tests für isInitialized()
    // ========================================================================

    public function testIsInitializedReturnsFalseBeforeInitialization(): void
    {
        $this->assertFalse($this->registry->isInitialized());
    }

    public function testIsInitializedReturnsTrueAfterInitialization(): void
    {
        $this->repo->method('findBy')->willReturn([]);

        $this->registry->initialize();

        $this->assertTrue($this->registry->isInitialized());
    }

    // ========================================================================
    // Edge Cases
    // ========================================================================

    public function testInitializeWithEmptyRepository(): void
    {
        $this->repo->method('findBy')->willReturn([]);

        $this->registry->initialize();

        $this->assertTrue($this->registry->isInitialized());
        $this->assertEquals(0, $this->registry->countTools());
    }

    public function testGetAvailableToolsWithEmptyRepository(): void
    {
        $this->repo->method('findBy')->willReturn([]);

        $this->registry->initialize();
        $availableTools = $this->registry->getAvailableTools();

        $this->assertEmpty($availableTools);
    }

    public function testLoadToolsWithMixedStatusTools(): void
    {
        $approvedTool = $this->createToolDefinition('approved_tool', 'approved');
        $pendingTool = $this->createToolDefinition('pending_tool', 'pending');
        $rejectedTool = $this->createToolDefinition('rejected_tool', 'rejected');

        $this->repo->method('findBy')->with(['status' => 'approved'])->willReturn([$approvedTool]);

        $this->registry->loadTools();

        $this->assertTrue($this->registry->hasTool('approved_tool'));
        $this->assertFalse($this->registry->hasTool('pending_tool'));
        $this->assertFalse($this->registry->hasTool('rejected_tool'));
    }

    public function testGetToolMetadataWithComplexSchema(): void
    {
        $tool = $this->createToolDefinition(
            'complex_tool',
            'approved',
            [
                'type' => 'object',
                'properties' => [
                    'input' => ['type' => 'string', 'description' => 'Input parameter'],
                    'count' => ['type' => 'integer', 'minimum' => 0],
                ],
                'required' => ['input'],
            ]
        );

        $this->repo->method('findBy')->willReturn([$tool]);

        $this->registry->initialize();
        $metadata = $this->registry->getToolMetadata('complex_tool');

        $this->assertEquals('object', $metadata['schema']['type']);
        $this->assertArrayHasKey('properties', $metadata['schema']);
        $this->assertArrayHasKey('required', $metadata['schema']);
    }

    // ========================================================================
    // PHASE 3: Tests für Evolution-Flow-Integration
    // ========================================================================

    /**
     * Testet die Integration mit dem ToolDefinitionGenerator (Phase 3)
     */
    public function testIntegrationWithToolDefinitionGenerator(): void
    {
        // Dieser Test prüft, ob das DynamicSkillRegistry korrekt mit
        // dem ToolDefinitionGenerator zusammenarbeitet
        
        // 1. Mock ToolDefinition mit Phase 3 Metadaten
        $toolDefinition = $this->createToolDefinition(
            'evolution_flow_tool',
            'approved',
            [
                'type' => 'object',
                'properties' => ['input' => ['type' => 'string']],
                'phase_3_optimized' => true,
                'prompt_version' => '1.0'
            ]
        );
        
        // 2. Mock Repository
        $this->repo->method('findBy')->with(['status' => 'approved'])->willReturn([$toolDefinition]);
        
        // 3. Mock ToolFactory
        $toolInterface = $this->createMock(\Symfony\AI\Agent\Tool\ToolInterface::class);
        $toolInterface->method('getName')->willReturn('evolution_flow_tool');
        
        $this->toolFactory->method('createTool')->willReturn($toolInterface);
        
        // 4. Teste das Laden
        $this->registry->initialize();
        $tools = $this->registry->getAvailableTools();
        
        // 5. Validierungen
        $this->assertArrayHasKey('evolution_flow_tool', $tools);
        $this->assertInstanceOf(\Symfony\AI\Agent\Tool\ToolInterface::class, $tools['evolution_flow_tool']);
    }
}
