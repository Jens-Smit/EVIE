<?php

namespace App\Tests\Unit\AI\Agent;

use App\AI\Agent\OrchestratorAgent;
use App\AI\Skills\DynamicSkillRegistry;
use App\AI\Onboarding\ContextStoreManager;
use PHPUnit\Framework\TestCase;

class OrchestratorAgentTest extends TestCase
{
    private OrchestratorAgent $orchestrator;
    private DynamicSkillRegistry $skillRegistry;
    private ContextStoreManager $contextStore;

    protected function setUp(): void
    {
        $this->skillRegistry = $this->createMock(DynamicSkillRegistry::class);
        $this->contextStore = $this->createMock(ContextStoreManager::class);
        
        $this->orchestrator = new OrchestratorAgent(
            $this->skillRegistry,
            $this->contextStore
        );
    }

    public function testHandlePromptWithAvailableTools(): void
    {
        $prompt = 'Analysiere diese Daten';
        $userIdentifier = 'user123';
        $context = ['user_type' => 'Business'];

        $this->contextStore->method('loadContext')
            ->with($userIdentifier)
            ->willReturn($context);

        $this->skillRegistry->method('getAvailableTools')
            ->willReturn([
                'DataAnalyzerTool' => $this->createMock(\stdClass::class),
            ]);

        $result = $this->orchestrator->handlePrompt($prompt, $userIdentifier);

        $this->assertEquals('execute_tools', $result['action']);
        $this->assertContains('DataAnalyzerTool', $result['available_tools']);
    }

    public function testHandlePromptWithMissingTools(): void
    {
        $prompt = 'Analysiere diese Excel-Datei';
        $userIdentifier = 'user123';
        $context = ['user_type' => 'Business'];

        $this->contextStore->method('loadContext')
            ->with($userIdentifier)
            ->willReturn($context);

        $this->skillRegistry->method('getAvailableTools')
            ->willReturn([]);

        $result = $this->orchestrator->handlePrompt($prompt, $userIdentifier);

        $this->assertEquals('trigger_tool_creation', $result['action']);
        $this->assertContains('ExcelParserTool', $result['missing_tools_list']);
    }

    public function testAnalyzePromptForExcel(): void
    {
        $prompt = 'Analysiere diese Excel-Datei';
        $context = [];

        $result = $this->orchestrator->handlePrompt($prompt, 'user123');

        $this->assertContains('ExcelParserTool', $result['required_tools']);
    }

    public function testAnalyzePromptForAnalysis(): void
    {
        $prompt = 'Ich möchte eine Datenanalyse durchführen';
        $context = [];

        $result = $this->orchestrator->handlePrompt($prompt, 'user123');

        $this->assertContains('DataAnalyzerTool', $result['required_tools']);
    }
}
