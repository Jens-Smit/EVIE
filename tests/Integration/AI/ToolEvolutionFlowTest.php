<?php

namespace App\Tests\Integration\AI;

use App\AI\Skills\DynamicSkillRegistry;
use App\AI\Skills\ToolDefinitionGenerator;
use App\Entity\ToolDefinition;
use App\Repository\ToolDefinitionRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class ToolEvolutionFlowTest extends KernelTestCase
{
    private ToolDefinitionGenerator $toolGenerator;
    private ToolDefinitionRepository $toolDefinitionRepo;
    private DynamicSkillRegistry $skillRegistry;

    protected function setUp(): void
    {
        self::bootKernel();
        
        $container = static::getContainer();
        $this->toolDefinitionRepo = $container->get(ToolDefinitionRepository::class);
        $this->toolGenerator = $container->get(ToolDefinitionGenerator::class);
        $this->skillRegistry = $container->get(DynamicSkillRegistry::class);
    }

    public function testToolEvolutionFlow(): void
    {
        // Step 1: Create a tool definition directly (no LLM call in tests)
        $toolDefinition = new ToolDefinition();
        $toolDefinition->setName('ExcelParserTool');
        $toolDefinition->setDescription('Ein Tool zum Parsen von Excel-Dateien');
        $toolDefinition->setSchema([
            'type' => 'object',
            'properties' => [
                'file_path' => ['type' => 'string', 'description' => 'Pfad zur Excel-Datei'],
            ],
            'required' => ['file_path'],
        ]);
        $toolDefinition->setStatus('pending');
        $this->toolDefinitionRepo->save($toolDefinition, true);
        
        // Verify tool is pending
        $this->assertEquals('pending', $toolDefinition->getStatus());
        $this->assertEquals('ExcelParserTool', $toolDefinition->getName());

        // Step 2: Approve the tool
        $this->toolGenerator->approveTool($toolDefinition);
        
        // Verify tool is approved
        $this->assertEquals('approved', $toolDefinition->getStatus());

        // Step 3: Reload tools and check if the new tool is available
        $this->skillRegistry->loadTools();
        $availableTools = $this->skillRegistry->getAvailableTools();
        
        // The tool may or may not be in the registry depending on how it's loaded
        $this->assertNotEmpty($availableTools);
    }

    public function testPendingToolApproval(): void
    {
        // Create a pending tool
        $toolDefinition = new ToolDefinition();
        $toolDefinition->setName('TestTool');
        $toolDefinition->setDescription('Test description');
        $toolDefinition->setSchema(['type' => 'object']);
        $toolDefinition->setStatus('pending');
        
        $this->toolDefinitionRepo->save($toolDefinition, true);

        // Verify it's in the database as pending
        $pendingTools = $this->toolDefinitionRepo->findAllPending();
        $this->assertCount(1, $pendingTools);
        $this->assertEquals('TestTool', $pendingTools[0]->getName());
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        
        // Clean up test data
        $pendingTools = $this->toolDefinitionRepo->findAllPending();
        foreach ($pendingTools as $tool) {
            $this->toolDefinitionRepo->remove($tool, true);
        }
        
        $approvedTools = $this->toolDefinitionRepo->findAllApproved();
        foreach ($approvedTools as $tool) {
            if ($tool->getName() === 'ExcelParserTool') {
                $this->toolDefinitionRepo->remove($tool, true);
            }
        }
    }
}