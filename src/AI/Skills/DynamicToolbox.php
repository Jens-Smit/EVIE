<?php

declare(strict_types=1);

namespace App\AI\Skills;

use App\Repository\ToolDefinitionRepository;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;

/**
 * Native Dynamic Toolbox für EVIE (Blueprint §4.B).
 *
 * Dekoriert die Symfony AI Toolbox des Orchestrators
 * (Service-ID "ai.toolbox.orchestrator") und ergänzt zur Laufzeit die aus der
 * Datenbank geladenen, freigegebenen ToolDefinition-Entities als native
 * Symfony\AI\Platform\Tool\Tool-Objekte.
 *
 * KEINE parallele DynamicSkillRegistry, KEIN Mock-Datenpfad: getTools()
 * liefert bei jedem Agent-Call die statischen Tools der inneren Toolbox
 * gemerged mit den dynamischen Tools (Status "approved").
 *
 * Die Dekoration wird über RegisterDynamicToolboxDecoratorPass registriert,
 * damit der Decorator nur dann aktiv wird, wenn der AI Bundle die
 * Orchestrator-Toolbox tatsächlich erzeugt hat (tools aktiviert).
 *
 * @see https://symfony.com/doc/current/ai/cookbook/dynamic-tools.html
 */
final class DynamicToolbox implements ToolboxInterface
{
    private const EXECUTOR_MAP = [
        'api' => 'App\\AI\\Skills\\Executor\\GenericApiExecutor',
        'database' => 'App\\AI\\Skills\\Executor\\GenericDatabaseExecutor',
        'filesystem' => 'App\\AI\\Skills\\Executor\\GenericFileExecutor',
        'http' => 'App\\AI\\Skills\\Executor\\GenericHttpExecutor',
        'generic' => 'App\\AI\\Skills\\Executor\\GenericExecutor',
    ];

    public function __construct(
        private readonly ToolboxInterface $innerToolbox,
        private readonly ToolDefinitionRepository $toolDefinitionRepository,
    ) {
    }

    public function getTools(): array
    {
        $tools = $this->innerToolbox->getTools();

        foreach ($this->loadApprovedDefinitions() as $definition) {
            $tools[] = $this->buildTool($definition);
        }

        return $tools;
    }

    public function execute(ToolCall $toolCall): ToolResult
    {
        return $this->innerToolbox->execute($toolCall);
    }

    /**
     * @return array<int, \App\Entity\ToolDefinition>
     */
    private function loadApprovedDefinitions(): array
    {
        try {
            return $this->toolDefinitionRepository->findBy(['status' => 'approved']);
        } catch (\Throwable) {
            // Während Tests / Cache-Warmup ohne DB-Anbindung ist die Tabelle
            // möglicherweise nicht verfügbar. In diesem Fall liefert die
            // Dynamic Toolbox nur die statischen Tools.
            return [];
        }
    }

    private function buildTool(\App\Entity\ToolDefinition $definition): Tool
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
