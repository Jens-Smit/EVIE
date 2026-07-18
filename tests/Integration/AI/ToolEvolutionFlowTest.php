<?php

namespace App\Tests\Integration\AI;

use App\AI\Agent\OrchestratorAgent;
use App\AI\Skills\DynamicSkillRegistry;
use App\AI\Skills\ToolDefinitionGenerator;
use App\AI\Onboarding\ContextStoreManager;
use App\Entity\ToolDefinition;
use App\Repository\ToolDefinitionRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class ToolEvolutionFlowTest extends KernelTestCase
{
    private OrchestratorAgent $orchestrator;
    private ToolDefinitionGenerator $toolGenerator;
    private ToolDefinitionRepository $toolDefinitionRepo;

    protected function setUp(): void
    {
        self::bootKernel();
        
        $container = static::getContainer();
        $this->toolDefinitionRepo = $container->get(ToolDefinitionRepository::class);
        
        $skillRegistry = $container->get(DynamicSkillRegistry::class);
        $contextStore = $container->get(ContextStoreManager::class);
        
        $this->orchestrator = new OrchestratorAgent($skillRegistry, $contextStore);
        $this->toolGenerator = new ToolDefinitionGenerator($this->toolDefinitionRepo, 'test_api_key');
    }

    public function testToolEvolutionFlow(): void
    {
        // Step 1: User requests a non-existent tool
        $prompt = 'Analysiere diese Excel-Datei';
        $userIdentifier = 'test_user';
        
        $result = $this->orchestrator->handlePrompt($prompt, $userIdentifier);
        
        // Should detect missing tool
        $this->assertEquals('trigger_tool_creation', $result['action']);
        $this->assertContains('ExcelParserTool', $result['missing_tools_list']);

        // Step 2: Generate a new tool definition
        $toolDefinition = $this->toolGenerator->generateToolDefinition(
            'ExcelParserTool',
            'Ein Tool zum Parsen von Excel-Dateien'
        );
        
        // Verify tool is pending
        $this->assertEquals('pending', $toolDefinition->getStatus());
        $this->assertEquals('ExcelParserTool', $toolDefinition->getName());

        // Step 3: Approve the tool
        $this->toolGenerator->approveTool($toolDefinition);
        
        // Verify tool is approved
        $this->assertEquals('approved', $toolDefinition->getStatus());

        // Step 4: Reload tools and check if the new tool is available
        $skillRegistry = self::getContainer()->get(DynamicSkillRegistry::class);
        $skillRegistry->loadTools();
        $availableTools = $skillRegistry->getAvailableTools();
        
        $this->assertArrayHasKey('ExcelParserTool', $availableTools);

        // Step 5: Re-run the prompt - should now find the tool
        $result = $this->orchestrator->handlePrompt($prompt, $userIdentifier);
        $this->assertEquals('execute_tools', $result['action']);
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
