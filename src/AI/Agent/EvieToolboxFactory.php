<?php
// src/AI/Agent/EvieToolboxFactory.php

namespace App\AI\Agent;

use App\AI\Skills\DynamicSkillRegistry;
use App\AI\Skills\Tool\ToolInterface;
use App\Mcp\Toolbox\McpToolFactoryWrapper;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Agent\Toolbox\ToolFactory\ChainFactory;
use Symfony\AI\Agent\Toolbox\ToolFactory\ReflectionToolFactory;
use Symfony\AI\Agent\Toolbox\Tool\Subagent;

final class EvieToolboxFactory
{
    public function __construct(
        private readonly McpToolFactoryWrapper $mcpToolFactory,
        private readonly iterable $nativeTools,
        private readonly DynamicSkillRegistry $dynamicSkillRegistry,
        private readonly SubAgentFactory $subAgentFactory,
    ) {
    }

    public function create(): Toolbox
    {
        $reflectionFactory = new ReflectionToolFactory();
        $chainFactory = new ChainFactory([$this->mcpToolFactory, $reflectionFactory]);
        $allTools = [];

        foreach ($this->nativeTools as $tool) {
            if ($tool instanceof ToolInterface) {
                $allTools[] = $tool;
            }
        }

        $subAgentTools = $this->subAgentFactory->createAllSubAgentTools();
        $agentNames = [
            'website_researcher', 'data_analyst', 'code_assistant', 'document_processor',
            'communication_manager', 'api_integration', 'project_manager', 'finance_manager',
            'hr_manager', 'marketing_manager', 'ceo_assistant'
        ];

        foreach ($agentNames as $agentName) {
            if (isset($subAgentTools[$agentName]) && $subAgentTools[$agentName] instanceof Subagent) {
                $safeSubAgentTool = $this->createSafeSubAgentToolWrapper($subAgentTools[$agentName], $agentName);
                $allTools[] = $safeSubAgentTool;
            }
        }

        foreach ($this->dynamicSkillRegistry->getAvailableTools() as $toolName => $metadata) {
            $dynamicTool = new class($toolName, $metadata) implements ToolInterface {
                private string $toolName;
                private array $metadata;

                public function __construct(string $toolName, array $metadata)
                {
                    $this->toolName = $toolName;
                    $this->metadata = $metadata;
                }

                public function getName(): string { return $this->toolName; }
                public function getDescription(): string { return $this->metadata['description'] ?? 'Dynamisch generiertes Tool'; }
                public function __invoke(array $parameters = []): array { return ['status' => 'error', 'message' => 'Tool-Ausführung muss über DynamicToolDispatcher erfolgen']; }
                public function __toString(): string { return $this->getName(); }
            };
            $allTools[] = $dynamicTool;
        }

        return new Toolbox($allTools, $chainFactory);
    }

    private function createSafeSubAgentToolWrapper(Subagent $subAgentTool, string $agentName): ToolInterface
    {
        return new class($subAgentTool, $agentName) implements ToolInterface {
            private Subagent $subAgentTool;
            private string $agentName;

            public function __construct(Subagent $subAgentTool, string $agentName)
            {
                $this->subAgentTool = $subAgentTool;
                $this->agentName = $agentName;
            }

            public function getName(): string { return 'subagent_' . $this->agentName; }
            public function getDescription(): string { return 'Sub-Agent für spezifische Aufgaben'; }
            public function __invoke(array $parameters = []): mixed
            {
                // Subagent::__invoke() erwartet einen String (die Nachricht an den Sub-Agenten)
                $message = $parameters['task'] ?? $parameters['message'] ?? $parameters['query'] ?? json_encode($parameters);
                return $this->subAgentTool->__invoke((string) $message);
            }
            public function __toString(): string { return $this->getName(); }
        };
    }

    public function createOrchestratorToolbox(): Toolbox
    {
        $reflectionFactory = new ReflectionToolFactory();
        $chainFactory = new ChainFactory([$this->mcpToolFactory, $reflectionFactory]);
        $orchestratorTools = [];

        // Sub-Agenten korrekt über Subagent-Wrapper einbinden
        $subAgentTools = $this->subAgentFactory->createAllSubAgentTools();
        $agentNames = [
            'website_researcher', 'data_analyst', 'code_assistant', 'document_processor',
            'communication_manager', 'api_integration', 'project_manager', 'finance_manager',
            'hr_manager', 'marketing_manager', 'ceo_assistant'
        ];

        foreach ($agentNames as $agentName) {
            if (isset($subAgentTools[$agentName]) && $subAgentTools[$agentName] instanceof Subagent) {
                $orchestratorTools[] = $this->createSafeSubAgentToolWrapper($subAgentTools[$agentName], $agentName);
            }
        }

        foreach ($this->dynamicSkillRegistry->getAvailableTools() as $toolName => $metadata) {
            $dynamicTool = new class($toolName, $metadata) implements ToolInterface {
                private string $toolName;
                private array $metadata;

                public function __construct(string $toolName, array $metadata)
                {
                    $this->toolName = $toolName;
                    $this->metadata = $metadata;
                }

                public function getName(): string { return $this->toolName; }
                public function getDescription(): string { return $this->metadata['description'] ?? 'Dynamisch generiertes Tool'; }
                public function __invoke(array $parameters = []): array { return ['status' => 'error', 'message' => 'Tool-Ausführung muss über DynamicToolDispatcher erfolgen']; }
                public function __toString(): string { return $this->getName(); }
            };
            $orchestratorTools[] = $dynamicTool;
        }

        return new Toolbox($orchestratorTools, $chainFactory);
    }

    public function createSubAgentToolbox(AgentInterface $subAgent): Toolbox
    {
        $reflectionFactory = new ReflectionToolFactory();
        $chainFactory = new ChainFactory([$this->mcpToolFactory, $reflectionFactory]);
        $subAgentTools = [];

        foreach ($this->nativeTools as $tool) {
            if ($tool instanceof ToolInterface) {
                $subAgentTools[] = $tool;
            }
        }

        return new Toolbox($subAgentTools, $chainFactory);
    }
}