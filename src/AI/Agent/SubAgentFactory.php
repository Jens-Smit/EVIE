<?php

namespace App\AI\Agent;

use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

#[Autoconfigure(tags: ['ai.agent_factory'])]
class SubAgentFactory
{
    private array $agentClasses = [];

    public function __construct()
    {
        // Register known agent types
        $this->agentClasses = [
            'research' => ResearchAgent::class,
            'analysis' => AnalysisAgent::class,
            'support' => SupportAgent::class,
        ];
    }

    /**
     * Creates a sub-agent instance based on the agent type.
     */
    public function createAgent(string $agentType, array $config = []): object
    {
        if (!isset($this->agentClasses[$agentType])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown agent type: %s. Available types: %s',
                $agentType,
                implode(', ', array_keys($this->agentClasses))
            ));
        }

        $agentClass = $this->agentClasses[$agentType];
        return new $agentClass($config);
    }

    /**
     * Returns the list of available agent types.
     */
    public function getAvailableAgentTypes(): array
    {
        return array_keys($this->agentClasses);
    }
}

// Placeholder classes for sub-agents
class ResearchAgent
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function execute(string $prompt): array
    {
        return ['result' => 'Research result for: ' . $prompt];
    }
}

class AnalysisAgent
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function execute(string $prompt): array
    {
        return ['result' => 'Analysis result for: ' . $prompt];
    }
}

class SupportAgent
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function execute(string $prompt): array
    {
        return ['result' => 'Support result for: ' . $prompt];
    }
}
