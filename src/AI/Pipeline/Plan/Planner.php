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
            $this->logger->debug('Planner: Intent=Unclear, clarify ohne LLM-Aufruf', [
                'intent' => $intent->name,
                'message' => $context->getMessage(),
            ]);
            return new Plan([new Step(Step::TYPE_CLARIFY, '', [], false, 'Anfrage mehrdeutig')]);
        }

        $this->logger->debug('Planner: Start Phase 3 (Plan)', [
            'intent' => $intent->name,
            'message' => $context->getMessage(),
        ]);

        try {
            $prompt = $this->buildPrompt($context);
            $this->logger->debug('Planner: Prompt an LLM gesendet', [
                'prompt' => $prompt,
                'model' => 'mistral-small-latest',
            ]);
            $messages = new MessageBag(Message::ofUser($prompt));
            $response = $this->platform->invoke('mistral-small-latest', $messages)->asText();
            $this->logger->debug('Planner: Rohe LLM-Antwort erhalten', [
                'response' => $response,
                'response_length' => strlen($response),
            ]);
            $plan = $this->parsePlan($response);

            if ($plan !== null) {
                $this->logger->debug('Planner: Plan erfolgreich geparst', [
                    'steps' => count($plan->getSteps()),
                    'summary' => $plan->getSummary(),
                ]);
                return $plan;
            }

            $this->logger->error('Planner: JSON-Parsing fehlgeschlagen, verwende clarify-Fallback', [
                'intent' => $intent->name,
                'message' => $context->getMessage(),
                'raw_response' => $response,
                'json_error' => json_last_error_msg(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Planner: LLM-Aufruf fehlgeschlagen, verwende clarify-Fallback', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'intent' => $intent->name,
                'user_message' => $context->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
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
        if (!is_array($data)) {
            $this->logger->debug('Planner.parsePlan: Antwort ist kein gueltiges JSON', [
                'json_error' => json_last_error_msg(),
                'response' => $response,
            ]);
            return null;
        }
        if (!isset($data['steps']) || !is_array($data['steps'])) {
            $this->logger->debug('Planner.parsePlan: Kein gueltiges steps-Feld', [
                'top_level_keys' => array_keys($data),
                'has_steps' => array_key_exists('steps', $data),
                'steps_is_array' => isset($data['steps']) && is_array($data['steps']),
            ]);
            return null;
        }

        $steps = [];
        foreach ($data['steps'] as $rawStep) {
            if (!is_array($rawStep)) {
                $this->logger->debug('Planner.parsePlan: Step uebersprungen (kein Array)', [
                    'raw_step' => $rawStep,
                ]);
                continue;
            }
            $steps[] = $this->buildStep($rawStep);
        }

        if (count($steps) === 0) {
            $this->logger->debug('Planner.parsePlan: Keine gueltigen Steps nach Filterung', [
                'raw_steps_count' => count($data['steps']),
            ]);
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
