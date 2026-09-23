<?php

declare(strict_types=1);

namespace App\AI\Pipeline\Execution;

use App\AI\Agent\LlmRetryExecutor;
use App\AI\Agent\SubAgentFactoryInterface;
use App\AI\Pipeline\Plan\Step;
use App\AI\Pipeline\PipelineContext;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;

/**
 * Phase 5: Fuehrt type=subagent-Schritte deterministisch aus.
 *
 * Der Sub-Agent wird direkt ueber die SubAgentFactory aufgerufen und
 * erhaelt als Aufgabe den task-Parameter des Schritts sowie die
 * Ergebnisse der in input_from referenzierten Schritte (z.B. das
 * Research-Ergebnis fuer den data_analyst). Damit wird aus einzelnen
 * Agenten ein Agentensystem: Ergebnis Schritt 1 -> Input Schritt 2.
 *
 * Keine Konstruktor-Injection einzelner Agenten; aufgeloest wird zur
 * Laufzeit ueber SubAgentFactoryInterface::createByName.
 *
 * @see docs/architecture/orchestrator-pipeline.md Phase 5
 */
final class SubAgentStepExecutor implements StepExecutorInterface
{
    public function __construct(
        private readonly SubAgentFactoryInterface $subAgentFactory,
        private readonly LlmRetryExecutor $llmRetryExecutor,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function supports(Step $step): bool
    {
        return $step->getType() === Step::TYPE_SUBAGENT;
    }

    public function execute(Step $step, PipelineContext $context, ExecutionState $state): mixed
    {
        $name = $step->getTarget();
        $task = $this->buildTask($step, $state);

        $this->logger->info('SubAgentStepExecutor: Fuehre Sub-Agent-Schritt aus', [
            'step_id' => $step->getId(),
            'subagent' => $name,
            'task_length' => strlen($task),
        ]);

        $subAgent = $this->subAgentFactory->createByName($name);
        $result = $this->llmRetryExecutor->callAgentWithRetry(
            $subAgent,
            new MessageBag(Message::ofUser($task))
        );
        $content = $result->getContent();

        if (!is_string($content) || trim($content) === '') {
            throw new \RuntimeException(sprintf(
                'Sub-Agent "%s" (Schritt "%s") lieferte ein leeres Ergebnis.',
                $name,
                $step->getId()
            ));
        }

        return $content;
    }

    /**
     * Baut die Aufgabe fuer den Sub-Agenten: task-Parameter (oder
     * reason als Fallback) plus die formatierten Ergebnisse der
     * input_from-Schritte, damit der Agent die Vorarbeit als Kontext
     * erhaelt und nicht blind starten muss.
     */
    private function buildTask(Step $step, ExecutionState $state): string
    {
        $parameters = $step->getParameters();
        $task = $parameters['task'] ?? null;
        if (!is_string($task) || trim($task) === '') {
            $task = $step->getReason() ?? sprintf('Fuehre die Aufgabe des Schritts "%s" aus.', $step->getId());
        }

        $inputs = $state->collect($step->getInputFrom());
        if ($inputs === []) {
            return $task;
        }

        return $task . "\n\nErgebnisse vorheriger Schritte:\n"
            . $this->formatInputs($inputs);
    }

    /**
     * @param array<string, mixed> $inputs
     */
    private function formatInputs(array $inputs): string
    {
        $sections = [];
        foreach ($inputs as $key => $value) {
            $sections[] = sprintf(
                "## %s\n%s",
                $key,
                is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            );
        }

        return implode("\n\n", $sections);
    }
}
