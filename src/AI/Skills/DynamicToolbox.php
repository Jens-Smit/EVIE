<?php

declare(strict_types=1);

namespace App\AI\Skills;

use App\AI\Agent\SubAgentFactoryInterface;
use App\Repository\ToolDefinitionRepository;
use App\Security\UserContext;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;

/**
 * Native Dynamic Toolbox fuer EVIE (Blueprint §4.B).
 *
 * Dekoriert die Symfony AI Toolbox des Orchestrators
 * (Service-ID "ai.toolbox.orchestrator") und ergaenzt zur Laufzeit die aus der
 * Datenbank geladenen, freigegebenen ToolDefinition-Entities als native
 * Symfony\AI\Platform\Tool\Tool-Objekte.
 *
 * KEINE parallele DynamicSkillRegistry, KEIN Mock-Datenpfad: getTools()
 * liefert bei jedem Agent-Call die statischen Tools der inneren Toolbox
 * gemerged mit den dynamischen Tools (Status "approved").
 *
 * P0-5 Tenant-Isolation: dynamische Tools werden pro Tenant geladen, sodass
 * Tenant A niemals Tools von Tenant B erhaelt. System-Tools
 * (user_identifier = NULL) bleiben fuer alle sichtbar.
 *
 * Die Dekoration wird ueber RegisterDynamicToolboxDecoratorPass registriert,
 * damit der Decorator nur dann aktiv wird, wenn der AI Bundle die
 * Orchestrator-Toolbox tatsaechlich erzeugt hat (tools aktiviert).
 *
 * @see https://symfony.com/doc/current/ai/cookbook/dynamic-tools.html
 */
final class DynamicToolbox implements ToolboxInterface
{
    private const SUB_AGENT_PREFIX = 'sub_agent_';

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
        private readonly UserContext $userContext,
        private readonly ?SubAgentFactoryInterface $subAgentFactory = null,
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
        // Sub-Agent-Delegation (Blueprint 4.B Multi-Agent): dynamische
        // sub_agent_*-Tools sind in der inneren Toolbox nicht registriert
        // und wurden bisher stillschweigend nicht ausgefuehrt. Hier wird
        // der Sub-Agent nativ ueber die SubAgentFactory aufgerufen und
        // dessen Antwort als ToolResult zurueckgegeben.
        if (str_starts_with($toolCall->getName(), self::SUB_AGENT_PREFIX) && $this->subAgentFactory !== null) {
            return $this->executeSubAgent($toolCall);
        }

        return $this->innerToolbox->execute($toolCall);
    }

    private function executeSubAgent(ToolCall $toolCall): ToolResult
    {
        $subAgentName = substr($toolCall->getName(), strlen(self::SUB_AGENT_PREFIX));
        $arguments = $toolCall->getArguments();
        $task = $arguments['task'] ?? $arguments['prompt'] ?? '';
        $task = is_string($task) && $task !== '' ? $task : 'Verarbeite die uebergebenen Parameter.';

        try {
            $subAgent = $this->subAgentFactory->createByName($subAgentName);
            $messages = new MessageBag(Message::ofUser($task));
            $result = $subAgent->call($messages);
            $content = $result->getContent();

            return new ToolResult($toolCall, is_string($content) ? $content : json_encode($content));
        } catch (\Throwable $e) {
            return new ToolResult($toolCall, 'Sub-Agent Fehler: ' . $e->getMessage());
        }
    }

    /**
     * @return array<int, \App\Entity\ToolDefinition>
     */
    private function loadApprovedDefinitions(): array
    {
        try {
            // P0-5: Tenant-Isolation. Ist ein User eingeloggt, werden nur
            // dessen Tools (+ systemweite Tools ohne Tenant-Bezug) geladen.
            $userIdentifier = $this->userContext->getUserIdentifier();
            if (null !== $userIdentifier) {
                return $this->toolDefinitionRepository->findApprovedForUser($userIdentifier);
            }

            return $this->toolDefinitionRepository->findAllApproved();
        } catch (\Throwable) {
            // Waehrend Tests / Cache-Warmup ohne DB-Anbindung ist die Tabelle
            // moeglicherweise nicht verfuegbar. In diesem Fall liefert die
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
