<?php

declare(strict_types=1);

namespace App\AI\Agent;

use App\Repository\ToolDefinitionRepository;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Agent\Toolbox\ToolFactory\ChainFactory;
use Symfony\AI\Agent\Toolbox\ToolFactory\ReflectionToolFactory;
use Symfony\AI\Agent\Toolbox\Tool\Subagent;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;

/**
 * Erzeugt nativen Symfony AI Toolboxes für den Orchestrator und Sub-Agents
 * (Blueprint §4.A, §4.C).
 *
 * Subagents werden als native Symfony\AI\Agent\Toolbox\Tool\Subagent-Instanzen
 * in die Toolbox aufgenommen — KEINE anonymen Tool-Wrapper, die Fehler
 * zurückgeben. Dynamische Tools (aus ToolDefinition-Entities) werden als
 * native Symfony\AI\Platform\Tool\Tool-Objekte gebaut und zur Laufzeit über
 * die DynamicToolbox ergänzt (Blueprint §4.B).
 */
final class EvieToolboxFactory
{
    private const EXECUTOR_MAP = [
        'api' => 'App\\AI\\Skills\\Executor\\GenericApiExecutor',
        'database' => 'App\\AI\\Skills\\Executor\\GenericDatabaseExecutor',
        'filesystem' => 'App\\AI\\Skills\\Executor\\GenericFileExecutor',
        'http' => 'App\\AI\\Skills\\Executor\\GenericHttpExecutor',
        'generic' => 'App\\AI\\Skills\\Executor\\GenericExecutor',
    ];

    private const SUBAGENT_NAMES = [
        'website_researcher', 'data_analyst', 'code_assistant', 'document_processor',
        'communication_manager', 'api_integration', 'project_manager', 'finance_manager',
        'hr_manager', 'marketing_manager', 'ceo_assistant',
    ];

    public function __construct(
        private readonly SubAgentFactory $subAgentFactory,
        private readonly ToolDefinitionRepository $toolDefinitionRepository,
    ) {
    }

    /**
     * Baut die Orchestrator-Toolbox: native Subagent-Tools + dynamische Tools
     * aus der Datenbank (Status "approved") als native Tool-Objekte.
     */
    public function createOrchestratorToolbox(): Toolbox
    {
        $chainFactory = new ChainFactory([new ReflectionToolFactory()]);
        $tools = $this->buildSubagentTools();

        foreach ($this->loadApprovedDefinitions() as $definition) {
            $tools[] = $this->buildDynamicTool($definition);
        }

        return new Toolbox($tools, $chainFactory);
    }

    /**
     * Alias für createOrchestratorToolbox (Blueprint §4.A).
     */
    public function create(): Toolbox
    {
        return $this->createOrchestratorToolbox();
    }

    /**
     * Baut eine Toolbox für einen Sub-Agenten: nur statische/native Tools.
     */
    public function createSubAgentToolbox(AgentInterface $subAgent): Toolbox
    {
        $chainFactory = new ChainFactory([new ReflectionToolFactory()]);

        return new Toolbox([], $chainFactory);
    }

    /**
     * @return Tool[]
     */
    private function buildSubagentTools(): array
    {
        $subAgentTools = $this->subAgentFactory->createAllSubAgentTools();
        $tools = [];

        foreach (self::SUBAGENT_NAMES as $agentName) {
            if (isset($subAgentTools[$agentName]) && $subAgentTools[$agentName] instanceof Subagent) {
                $tools[] = $subAgentTools[$agentName];
            }
        }

        return $tools;
    }

    /**
     * @return array<int, \App\Entity\ToolDefinition>
     */
    private function loadApprovedDefinitions(): array
    {
        try {
            return $this->toolDefinitionRepository->findBy(['status' => 'approved']);
        } catch (\Throwable) {
            return [];
        }
    }

    private function buildDynamicTool(\App\Entity\ToolDefinition $definition): Tool
    {
        $executorClass = self::EXECUTOR_MAP[$definition->getExecutorType() ?? 'generic']
            ?? self::EXECUTOR_MAP['generic'];

        return new Tool(
            new ExecutionReference($executorClass),
            $definition->getName() ?? '',
            $definition->getDescription() ?? '',
            $definition->getSchema() ?: null,
        );
    }
}
