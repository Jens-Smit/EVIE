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
        $this->assertGrounded($name, $step, $content);

        return $content;
    }

    /**
     * Grounding-Gate fuer den website_researcher: Das Ergebnis muss
     * erkennbar auf einen echten Tool-Abruf der Ziel-URL zurueckgehen
     * und darf nicht aus Modellwissen erzeugt sein. Geprueft wird das
     * JSON-Ergebnis-Schema aus config/packages/ai.yaml:
     *  - kein Abbruch-Signal (fehler-Feld),
     *  - quellen/url enthaelt mindestens eine URL,
     *  - geschaeftszweck oder branche ist nicht-leer.
     * Ohne diese Pruefung floss im Log-Fall visiongastro ein frei
     * erfundenes Research-JSON ungeprueft in Analyse und Synthese.
     */
    private function assertGrounded(string $name, Step $step, string $content): void
    {
        if ($name !== 'website_researcher') {
            return;
        }
        $data = $this->decodeJsonResult($content);
        if ($data === null) {
            throw new \App\AI\Pipeline\Exception\UngroundedResearchException(sprintf(
                'website_researcher (Schritt "%s") lieferte kein gueltiges JSON-Ergebnis. '
                . 'Ohne maschinenlesbares Ergebnis kann der Abruf nicht gegen Halluzination '
                . 'geprueft werden; die Ausfuehrung wird abgebrochen.',
                $step->getId()
            ));
        }
        if (is_string($data['fehler'] ?? null) && trim($data['fehler']) !== '') {
            throw new \App\AI\Pipeline\Exception\UngroundedResearchException(sprintf(
                'website_researcher (Schritt "%s") konnte die Website nicht abrufen: %s '
                . 'Der Plan muss stoppen statt aus Modellwissen zu generieren; '
                . 'gegebenenfalls muss der Nutzer per Rueckfrage fehlende Angaben ergaenzen.',
                $step->getId(),
                trim($data['fehler'])
            ));
        }
        $sources = $data['quellen'] ?? $data['url'] ?? null;
        $sourceList = is_array($sources) ? $sources : (is_string($sources) ? [$sources] : []);
        $hasSource = false;
        foreach ($sourceList as $source) {
            if (is_string($source) && preg_match('~https?://~i', $source) === 1) {
                $hasSource = true;
                break;
            }
        }
        if (!$hasSource) {
            throw new \App\AI\Pipeline\Exception\UngroundedResearchException(sprintf(
                'website_researcher (Schritt "%s") nannte keine Quell-URL (Feld "quellen"/"url" leer). '
                . 'Ein Ergebnis ohne belegte Quelle ist nicht verifizierbar und wird als '
                . 'halluziniert verworfen.',
                $step->getId()
            ));
        }
        foreach (['geschaeftszweck', 'geschäftszweck', 'branche'] as $field) {
            $value = $data[$field] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return;
            }
        }
        throw new \App\AI\Pipeline\Exception\UngroundedResearchException(sprintf(
            'website_researcher (Schritt "%s") lieferte weder geschaeftszweck noch branche. '
            . 'Der Abruf war offenbar nicht erfolgreich; die Ausfuehrung wird abgebrochen, '
            . 'statt ein Dokument auf Basis von Modellwissen zu erzeugen.',
            $step->getId()
        ));
    }

    /**
     * Dekodiert die LLM-Antwort als JSON; akzeptiert einen JSON-Block
     * in Markdown-Fences. Liefert null, wenn kein Objekt dekodierbar ist.
     *
     * @return array<string, mixed>|null
     */
    private function decodeJsonResult(string $content): ?array
    {
        $trimmed = trim($content);
        if (preg_match('/```(?:json)?\s*\n(.*?)```/s', $trimmed, $m) === 1) {
            $trimmed = trim($m[1]);
        }
        $data = json_decode($trimmed, true);
        return is_array($data) ? $data : null;
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
