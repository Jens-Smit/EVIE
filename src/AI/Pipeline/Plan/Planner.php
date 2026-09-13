<?php

declare(strict_types=1);

namespace App\AI\Pipeline\Plan;

use App\AI\Agent\SubAgentFactoryInterface;
use App\AI\Pipeline\Intent\Intent;
use App\AI\Pipeline\PipelineContext;
use App\AI\Skills\Tool\ToolRegistry;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;

/**
 * Phase 3: Erzeugt einen geordneten Plan aus Intent und Kontext.
 *
 * Der Planner ist ein reiner LLM-Aufruf (kein Tool-Calling) mit dem Prompt
 * config/prompts/planner.txt. Er kennt die verfuegbaren Tools (ToolRegistry)
 * und Sub-Agenten (SubAgentFactoryInterface) als Kontext und darf keine
 * neuen Tools erfinden; fehlende Faehigkeiten werden als
 * Step{needs_capability: true} markiert und in Phase 4 behandelt.
 *
 * Fallbacks:
 *  - unclear -> clarify-Plan (Rueckfrage, keine Capability)
 *  - LLM-Ausfall / ungueltiges JSON -> clarify-Plan, damit die Pipeline
 *    ohne Capability-Generierung fortgesetzt wird.
 *
 * @see docs/architecture/orchestrator-pipeline.md Phase 3
 */
final class Planner implements PlannerInterface
{
    private PlatformInterface $platform;
    private ToolRegistry $toolRegistry;
    private SubAgentFactoryInterface $subAgentFactory;
    private LoggerInterface $logger;
    private string $promptTemplate;

    public function __construct(
        PlatformInterface $platform,
        ToolRegistry $toolRegistry,
        SubAgentFactoryInterface $subAgentFactory,
        LoggerInterface $logger,
        string $promptFile
    ) {
        $this->platform = $platform;
        $this->toolRegistry = $toolRegistry;
        $this->subAgentFactory = $subAgentFactory;
        $this->logger = $logger;
        $this->promptTemplate = file_get_contents($promptFile) ?: '';
    }

    public function plan(PipelineContext $context, Intent $intent): Plan
    {
        // unclear -> direkt clarify, ohne LLM-Aufruf.
        if ($intent === Intent::Unclear) {
            return new Plan([new Step(Step::TYPE_CLARIFY, '', [], false, 'Anfrage mehrdeutig')]);
        }

        try {
            $prompt = $this->buildPrompt($context);
            $messages = new MessageBag(Message::ofUser($prompt));
            $response = $this->platform->invoke('mistral-small-latest', $messages)->asText();
            $plan = $this->parsePlan($response);

            if ($plan !== null) {
                return $plan;
            }
        } catch (\Exception $e) {
            $this->logger->warning('Planner: LLM fehlgeschlagen, verwende clarify-Fallback: ' . $e->getMessage());
        }

        return new Plan([new Step(Step::TYPE_CLARIFY, '', [], false, 'Plan konnte nicht erstellt werden')]);
    }

    private function buildPrompt(PipelineContext $context): string
    {
        $tools = implode(', ', array_keys($this->toolRegistry->all()));
        $subAgents = implode(', ', array_keys($this->subAgentFactory->getAvailableSubAgents()));

        return strtr($this->promptTemplate, [
            '__AVAILABLE_TOOLS__' => $tools !== '' ? $tools : '(keine)',
            '__AVAILABLE_SUBAGENTS__' => $subAgents !== '' ? $subAgents : '(keine)',
            '__USER_MESSAGE__' => $context->getMessage(),
        ]);
    }

    /**
     * Parst die LLM-JSON-Antwort in einen Plan. Liefert null bei
     * ungueltigem JSON oder leerer Schrittliste; der Aufrufer faellt
     * dann auf einen clarify-Plan zurueck.
     */
    private function parsePlan(string $response): ?Plan
    {
        $data = json_decode($response, true);
        if (!is_array($data) || !isset($data['steps']) || !is_array($data['steps'])) {
            return null;
        }

        $steps = [];
        foreach ($data['steps'] as $rawStep) {
            if (!is_array($rawStep)) {
                continue;
            }
            $steps[] = $this->buildStep($rawStep);
        }

        if (count($steps) === 0) {
            return null;
        }

        $summary = is_string($data['summary'] ?? null) ? $data['summary'] : null;

        return new Plan($steps, $summary);
    }

    private function buildStep(array $raw): Step
    {
        $type = is_string($raw['type'] ?? null) ? $raw['type'] : Step::TYPE_CLARIFY;
        $target = is_string($raw['target'] ?? null) ? $raw['target'] : '';
        $parameters = is_array($raw['parameters'] ?? null) ? $raw['parameters'] : [];
        $needsCapability = is_bool($raw['needs_capability'] ?? null) ? $raw['needs_capability'] : false;
        $reason = is_string($raw['reason'] ?? null) ? $raw['reason'] : null;

        return new Step($type, $target, $parameters, $needsCapability, $reason);
    }
}
