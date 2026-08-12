<?php

namespace App\Tests\Integration\AI\Skills;

use App\AI\Skills\DynamicSkillRegistry;
use App\AI\Skills\Tool\DynamicToolFactory;
use App\Entity\ToolDefinition;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integrationstests für DynamicSkillRegistry
 * 
 * Testet:
 * - Tool-Loading aus der echten Datenbank
 * - Tool-Registrierung
 * - Integration mit DynamicToolFactory
 */
final class DynamicSkillRegistryIntegrationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private DynamicSkillRegistry $registry;
    private DynamicToolFactory $toolFactory;

    protected function setUp(): void
    {
        self::bootKernel();
        
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->registry = self::getContainer()->get(DynamicSkillRegistry::class);
        $this->toolFactory = self::getContainer()->get(DynamicToolFactory::class);
    }

    // ========================================================================
    // Helper-Methoden
    // ========================================================================

    private function createAndPersistToolDefinition(
        string $name = 'test_tool',
        string $status = 'approved',
        array $schema = []
    ): ToolDefinition {
        $toolDefinition = new ToolDefinition();
        $toolDefinition->setName($name);
        $toolDefinition->setDescription('Integration Test Tool');
        $toolDefinition->setStatus($status);
        $toolDefinition->setSchema($schema);
        $toolDefinition->setParameters([
            ['name' => 'input', 'type' => 'string', 'required' => true],
        ]);

        $this->em->persist($toolDefinition);
        $this->em->flush();

        return $toolDefinition;
    }

    // ========================================================================
    // Tests für Tool-Loading aus der DB
    // ========================================================================

    public function testLoadApprovedToolsFromDatabase(): void
    {
        // Erstelle Test-Tools in der DB
        $tool1 = $this->createAndPersistToolDefinition('integration_tool_1', 'approved', ['type' => 'object']);
        $tool2 = $this->createAndPersistToolDefinition('integration_tool_2', 'approved', ['type' => 'string']);
        $tool3 = $this->createAndPersistToolDefinition('integration_tool_3', 'pending', []);

        // Lade Tools aus der DB
        $this->registry->loadTools();

        // Prüfe, dass nur genehmigte Tools geladen wurden
        $this->assertTrue($this->registry->hasTool('integration_tool_1'));
        $this->assertTrue($this->registry->hasTool('integration_tool_2'));
        $this->assertFalse($this->registry->hasTool('integration_tool_3'));
    }

    public function testGetToolFromDatabase(): void
    {
        $tool = $this->createAndPersistToolDefinition('db_tool', 'approved', ['type' => 'object']);

        $this->registry->loadTools();
        $retrievedTool = $this->registry->getTool('db_tool');

        $this->assertInstanceOf(ToolDefinition::class, $retrievedTool);
        $this->assertEquals('db_tool', $retrievedTool->getName());
        $this->assertEquals('Integration Test Tool', $retrievedTool->getDescription());
    }

    public function testGetToolMetadataFromDatabase(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'url' => ['type' => 'string'],
                'depth' => ['type' => 'integer'],
            ],
        ];

        $tool = $this->createAndPersistToolDefinition('metadata_tool', 'approved', $schema);

        $this->registry->loadTools();
        $metadata = $this->registry->getToolMetadata('metadata_tool');

        $this->assertIsArray($metadata);
        $this->assertEquals('metadata_tool', $metadata['name']);
        $this->assertEquals($schema, $metadata['schema']);
    }

    // ========================================================================
    // Tests für Tool-Registrierung
    // ========================================================================

    public function testAddToolManually(): void
    {
        $tool = $this->createAndPersistToolDefinition('manual_tool', 'approved');

        // Füge Tool manuell hinzu (ohne DB-Load)
        $this->registry->addTool($tool);

        $this->assertTrue($this->registry->hasTool('manual_tool'));
        $this->assertEquals(1, $this->registry->countTools());
    }

    public function testRemoveToolManually(): void
    {
        $tool = $this->createAndPersistToolDefinition('removable_tool', 'approved');
        $this->registry->addTool($tool);

        $this->assertTrue($this->registry->hasTool('removable_tool'));

        $this->registry->removeTool('removable_tool');
        $this->assertFalse($this->registry->hasTool('removable_tool'));
    }

    // ========================================================================
    // Tests für Tool-Factory-Integration
    // ========================================================================

    public function testCreateToolWithDynamicToolFactory(): void
    {
        $toolDefinition = $this->createAndPersistToolDefinition(
            'factory_tool',
            'approved',
            ['service' => 'App\AI\Skills\Tool\GenericApiExecutor']
        );

        $this->registry->loadTools();

        // Prüfe, dass das Tool erstellt werden kann
        $this->assertTrue($this->toolFactory->canCreateTool($toolDefinition));
    }

    public function testCreateToolWithPendingToolFails(): void
    {
        $toolDefinition = $this->createAndPersistToolDefinition('pending_factory_tool', 'pending');

        $this->registry->loadTools();

        // Prüfe, dass das Tool nicht erstellt werden kann
        $this->assertFalse($this->toolFactory->canCreateTool($toolDefinition));
    }

    // ========================================================================
    // Tests für reload()
    // ========================================================================

    public function testReloadToolsFromDatabase(): void
    {
        // Erstelle erstes Tool
        $tool1 = $this->createAndPersistToolDefinition('reload_tool_1', 'approved');
        $this->registry->loadTools();
        $this->assertTrue($this->registry->hasTool('reload_tool_1'));

        // Erstelle zweites Tool
        $tool2 = $this->createAndPersistToolDefinition('reload_tool_2', 'approved');

        // Lade neu
        $this->registry->reload();

        // Beide Tools sollten jetzt verfügbar sein
        $this->assertTrue($this->registry->hasTool('reload_tool_1'));
        $this->assertTrue($this->registry->hasTool('reload_tool_2'));
    }

    // ========================================================================
    // Tests für getAvailableTools()
    // ========================================================================

    public function testGetAvailableToolsReturnsAllFromDatabase(): void
    {
        $tool1 = $this->createAndPersistToolDefinition('available_tool_1', 'approved', ['type' => 'object']);
        $tool2 = $this->createAndPersistToolDefinition('available_tool_2', 'approved', ['type' => 'string']);

        $this->registry->loadTools();
        $availableTools = $this->registry->getAvailableTools();

        $this->assertArrayHasKey('available_tool_1', $availableTools);
        $this->assertArrayHasKey('available_tool_2', $availableTools);
        $this->assertCount(2, $availableTools);
    }

    // ========================================================================
    // Tests für getToolNames()
    // ========================================================================

    public function testGetToolNamesReturnsAllFromDatabase(): void
    {
        $tool1 = $this->createAndPersistToolDefinition('names_tool_1', 'approved');
        $tool2 = $this->createAndPersistToolDefinition('names_tool_2', 'approved');

        $this->registry->loadTools();
        $toolNames = $this->registry->getToolNames();

        $this->assertContains('names_tool_1', $toolNames);
        $this->assertContains('names_tool_2', $toolNames);
        $this->assertCount(2, $toolNames);
    }

    // ========================================================================
    // Tests für countTools()
    // ========================================================================

    public function testCountToolsReturnsCorrectCountFromDatabase(): void
    {
        $tool1 = $this->createAndPersistToolDefinition('count_tool_1', 'approved');
        $tool2 = $this->createAndPersistToolDefinition('count_tool_2', 'approved');
        $tool3 = $this->createAndPersistToolDefinition('count_tool_3', 'approved');

        $this->registry->loadTools();

        $this->assertEquals(3, $this->registry->countTools());
    }

    // ========================================================================
    // Cleanup
    // ========================================================================

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->em->close();
    }
}
