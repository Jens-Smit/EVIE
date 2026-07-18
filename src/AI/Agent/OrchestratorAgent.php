<?php

namespace App\AI\Agent;

use App\AI\Skills\DynamicSkillRegistry;
use App\AI\Onboarding\ContextStoreManager;
use Symfony\Component\DependencyInjection\Attribute\AsAgent;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

#[AsAgent(name: 'orchestrator')]
#[Autoconfigure(tags: ['ai.agent'])]
class OrchestratorAgent
{
    private DynamicSkillRegistry $skillRegistry;
    private ContextStoreManager $contextStore;

    public function __construct(
        DynamicSkillRegistry $skillRegistry,
        ContextStoreManager $contextStore
    ) {
        $this->skillRegistry = $skillRegistry;
        $this->contextStore = $contextStore;
    }

    /**
     * Analyzes the user's intent and delegates to appropriate tools or sub-agents.
     */
    public function handlePrompt(string $prompt, string $userIdentifier): array
    {
        // Load user context
        $context = $this->contextStore->loadContext($userIdentifier);
        
        // Analyze prompt and determine required tools
        $requiredTools = $this->analyzePrompt($prompt, $context);
        
        // Check if tools are available
        $availableTools = $this->skillRegistry->getAvailableTools();
        
        $response = [
            'prompt' => $prompt,
            'context' => $context,
            'required_tools' => $requiredTools,
            'available_tools' => array_keys($availableTools),
            'missing_tools' => array_diff($requiredTools, array_keys($availableTools)),
        ];

        // If tools are missing, trigger tool creation
        if (!empty($response['missing_tools'])) {
            $response['action'] = 'trigger_tool_creation';
            $response['missing_tools_list'] = $response['missing_tools'];
        } else {
            // Execute available tools
            $response['action'] = 'execute_tools';
            $response['execution_results'] = $this->executeTools($requiredTools, $prompt, $userIdentifier);
        }

        return $response;
    }

    /**
     * Analyzes the prompt and returns required tool names.
     */
    private function analyzePrompt(string $prompt, array $context): array
    {
        // Placeholder for Mistral LLM analysis
        // This would be replaced with actual LLM call in production
        $requiredTools = [];
        
        // Example logic: Check for keywords
        if (str_contains(strtolower($prompt), 'excel')) {
            $requiredTools[] = 'ExcelParserTool';
        }
        
        if (str_contains(strtolower($prompt), 'analysieren') || str_contains(strtolower($prompt), 'analyse')) {
            $requiredTools[] = 'DataAnalyzerTool';
        }

        return array_unique($requiredTools);
    }

    /**
     * Executes the required tools.
     */
    private function executeTools(array $toolNames, string $prompt, string $userIdentifier): array
    {
        $results = [];
        
        foreach ($toolNames as $toolName) {
            try {
                $tool = $this->skillRegistry->getTool($toolName);
                $results[$toolName] = $tool->execute($prompt, $userIdentifier);
            } catch (\Exception $e) {
                $results[$toolName] = ['error' => $e->getMessage()];
            }
        }

        return $results;
    }
}
