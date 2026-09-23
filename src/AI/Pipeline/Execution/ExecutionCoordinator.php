<?php

declare(strict_types=1);

namespace App\AI\Pipeline\Execution;

use App\AI\Agent\LlmRetryExecutor;
use App\AI\Pipeline\Capability\CapabilityResult;
use App\AI\Pipeline\Plan\Plan;
use App\AI\Pipeline\Plan\Step;
use App\AI\Pipeline\PipelineContext;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Phase 5 + Antwortformatierung: Erzeugt die finale PipelineResult-Antwort.
 *
 * Die Ausfuehrung (execute) arbeitet den Plan deterministisch ab: Der
 * Coordinator iteriert ueber die geordneten Steps, delegiert jeden
 * Schritt an den unterstuetzenden StepExecutor (tool -> ToolStepExecutor,
 * subagent -> SubAgentStepExecutor), speichert jedes Ergebnis im
 * ExecutionState unter dem output_key und reicht die Ergebnisse der
 * input_from-Steps an nachfolgende Schritte weiter. Das LLM entscheidet
 * NICHT erneut, ob ein Schritt ausgefuehrt wird — der Plan ist die
 * Entscheidung. schliesst der letzte Schritt die Antwort ab, wird sie
 * als Textantwort geliefert; sonst fasst der Coordinator die
 * Schrittergebnisse zusammen.
 *
 * Die Formatierungsmethoden (dialog/clarify/awaitingApproval) erzeugen
 * die User-Antwort fuer die Exit-Gates der Phasen 2-4, ohne eine
 * Capability zu generieren oder eine Aktion auszuloesen.
 *
 * Keine neuen Symfony-AI-Bridges, keine Konstruktor-Injection fuer Tools;
 * der native Agent wird als Service injiziert.
 *
 * @see docs/architecture/orchestrator-pipeline.md Phase 5
 */
final class ExecutionCoordinator implements ExecutionCoordinatorInterface
{
    private AgentInterface $orchestratorAgent;
    private LlmRetryExecutor $llmRetryExecutor;
    private UrlGeneratorInterface $urlGenerator;
    private LoggerInterface $logger;
    /** @var list<StepExecutorInterface> */
    private array $stepExecutors;

    /**
     * @param iterable<StepExecutorInterface> $stepExecutors Tagged Iterator
     *        (app.ai.step_executor); wird beim Konstruktor-Aufruf in eine
     *        Liste materialisiert.
     */
    public function __construct(
        #[Autowire(service: 'ai.agent.orchestrator')]
        AgentInterface $orchestratorAgent,
        LlmRetryExecutor $llmRetryExecutor,
        UrlGeneratorInterface $urlGenerator,
        LoggerInterface $logger,
        iterable $stepExecutors = []
    ) {
        $this->orchestratorAgent = $orchestratorAgent;
        $this->llmRetryExecutor = $llmRetryExecutor;
        $this->urlGenerator = $urlGenerator;
        $this->logger = $logger;
        $this->stepExecutors = [];
        foreach ($stepExecutors as $executor) {
            $this->stepExecutors[] = $executor;
        }
    }

    public function dialog(PipelineContext $context): PipelineResult
    {
        // Phase 2 Exit-Gate: Konversation/Information. Der native
        // Orchestrator-Agent antwortet dialogorientiert (sein Prompt
        // erzeugt type 'dialog'); keine Tool-Generierung.
        try {
            $messages = $this->buildMessageBag($context);
            $result = $this->llmRetryExecutor->callAgentWithRetry($this->orchestratorAgent, $messages);
            $content = $result->getContent();

            if (!is_string($content) || $content === '') {
                $content = 'Ich bin EVIE. Wie kann ich dir helfen?';
            }

            return new PipelineResult(PipelineResult::TYPE_DIALOG, $content);
        } catch (\Exception $e) {
            $this->logger->warning('ExecutionCoordinator::dialog LLM-Ausfall: ' . $e->getMessage());

            return new PipelineResult(
                PipelineResult::TYPE_DIALOG,
                'Ich konnte deine Anfrage gerade leider nicht verarbeiten. '
                . 'Kannst du sie bitte etwas anders formulieren oder praeziser '
                . 'beschreiben, was du moechtest?'
            );
        }
    }

    public function clarify(PipelineContext $context, Plan $plan): PipelineResult
    {
        // Phase 3 Exit-Gate: Rueckfrage bei unklarer Anfrage. Bevorzugt
        // den Grund aus dem clarify-Step; sonst generische Rueckfrage.
        $step = $plan->getSteps()[0] ?? null;
        $reason = $step !== null ? $step->getReason() : null;

        $content = $reason !== null && $reason !== ''
            ? 'Damit ich dir richtig helfen kann, brauche ich noch etwas mehr Info: '
                . $reason
                . ' Kannst du genauer beschreiben, was du moechtest?'
            : 'Ich bin mir nicht ganz sicher, was du genau moechtest. '
                . 'Moechtest du nur darueber reden, eine Information haben oder '
                . 'soll ich eine konkrete Aktion ausfuehren (z.B. eine API abrufen, '
                . 'eine Datei analysieren)? Bitte beschreibe es etwas genauer.';

        return new PipelineResult(PipelineResult::TYPE_CLARIFY, $content);
    }

    public function awaitingApproval(PipelineContext $context, CapabilityResult $result): PipelineResult
    {
        // Phase 4 Exit-Gate: Capability fehlt oder wartet auf Freigabe (HITL).
        $toolDefinitionId = $result->getToolDefinitionId();
        $toolsUrl = $this->urlGenerator->generate('app_tool_pending_list', [], UrlGeneratorInterface::ABSOLUTE_URL);

        if ($result->getDecision()->isPending() && $toolDefinitionId !== null) {
            $content = sprintf(
                "Ich habe ein neues Werkzeug fuer deine Anfrage entworfen, das vor der " .
                "Ausfuehrung freigegeben werden muss.\n\n" .
                "\xF0\x9F\x91\x89 **Freigeben/Ablehnen:** %s (Tool-ID %d)\n\n" .
                "Sobald du das Werkzeug freigibst, fahre ich mit deiner Anfrage fort.",
                $toolsUrl,
                $toolDefinitionId
            );

            return new PipelineResult(PipelineResult::TYPE_AWAITING_APPROVAL, $content, $toolDefinitionId);
        }

        // Missing ohne generierte Definition: kein Tool moeglich.
        return new PipelineResult(
            PipelineResult::TYPE_AWAITING_APPROVAL,
            'Ich habe aktuell kein passendes Werkzeug fuer diese Anfrage und konnte ' .
            'auch keines entwerfen. Kannst du die Anfrage praeziser stellen oder ' .
            'auf eine bestaehende Faehigkeit beziehen?'
        );
    }

    public function execute(PipelineContext $context, Plan $plan): PipelineResult
    {
        // Phase 5: Deterministische Ausfuehrung des freigegebenen Plans.
        // Der Coordinator arbeitet die geordneten Steps ab; jeder Schritt
        // wird ueber einen StepExecutor ausgefuehrt, das Ergebnis im
        // ExecutionState gespeichert und an abhaengige Schritte
        // weitergereicht. Fehlgeschlagene Schritte stoppen den Workflow
        // (keine Halluzination desfinalen Ergebnisses).
        $state = new ExecutionState();
        $steps = $this->sortByDependencies($plan->getSteps());
        $lastResult = null;

        try {
            foreach ($steps as $step) {
                $executor = $this->findExecutor($step);
                if ($executor === null) {
                    throw new \RuntimeException(sprintf(
                        'Kein StepExecutor fuer Schritt "%s" (type "%s").',
                        $step->getId(),
                        $step->getType()
                    ));
                }

                $this->logger->info('ExecutionCoordinator: Schritt gestartet', [
                    'step_id' => $step->getId(),
                    'type' => $step->getType(),
                    'target' => $step->getTarget(),
                ]);

                $result = $executor->execute($step, $context, $state);
                $state->set($step->resolvedOutputKey(), $result);
                $lastResult = $result;

                $this->logger->info('ExecutionCoordinator: Schritt abgeschlossen', [
                    'step_id' => $step->getId(),
                    'output_key' => $step->resolvedOutputKey(),
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error('ExecutionCoordinator::execute fehlgeschlagen: ' . $e->getMessage(), [
                'step_id' => $step->getId(),
                'step_target' => $step->getTarget(),
            ]);

            return new PipelineResult(
                PipelineResult::TYPE_ERROR,
                'Bei der Ausfuehrung ist ein Fehler aufgetreten: ' . $e->getMessage()
            );
        }

        return new PipelineResult(
            PipelineResult::TYPE_EXECUTED,
            $this->formatFinalAnswer($lastResult, $plan, $state)
        );
    }

    /**
     * Sortiert die Steps nach ihren depends_on-Angaben (stabile
     * Topologie): Steps ohne Abhaengigkeiten zuerst, danach Steps,
     * deren Abhaengigkeiten bereits eingeplant sind. Zyklen werden wie
     * ungeloesste Abhaengigkeiten behandelt und erhalten ihre Original-
     * Position bei (Fehler wird bei der Ausfuehrung sichtbar).
     *
     * @param list<Step> $steps
     * @return list<Step>
     */
    private function sortByDependencies(array $steps): array
    {
        $ids = [];
        foreach ($steps as $step) {
            $ids[$step->getId()] = $step;
        }

        $remaining = $steps;
        $sorted = [];
        $scheduled = [];
        while ($remaining !== []) {
            $progress = false;
            $next = [];
            foreach ($remaining as $step) {
                $ready = true;
                foreach ($step->getDependsOn() as $dependency) {
                    if (!isset($scheduled[$dependency])) {
                        $ready = false;
                        break;
                    }
                }
                if ($ready) {
                    $sorted[] = $step;
                    $scheduled[$step->getId()] = true;
                    $progress = true;
                } else {
                    $next[] = $step;
                }
            }
            $remaining = $next;
            if (!$progress) {
                // Zyklus oder unbekannte Abhaengigkeit: Original-Reihenfolge
                // beibehalten, damit der Workflow nicht still haengt.
                foreach ($remaining as $step) {
                    $sorted[] = $step;
                }
                break;
            }
        }

        return $sorted;
    }

    private function findExecutor(Step $step): ?StepExecutorInterface
    {
        foreach ($this->stepExecutors as $executor) {
            if ($executor->supports($step)) {
                return $executor;
            }
        }

        return null;
    }

    /**
     * Formatiert die finale Antwort: bevorzugt das Ergebnis des letzten
     * Schritts (z.B. der Businessplan aus dem synthesis-Schritt); sonst
     * eine strukturierte Zusammenfassung aller Schrittergebnisse.
     */
    private function formatFinalAnswer(mixed $lastResult, Plan $plan, ExecutionState $state): string
    {
        if (is_string($lastResult) && trim($lastResult) !== '') {
            return $lastResult;
        }
        if (is_array($lastResult)) {
            $encoded = json_encode($lastResult, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            if (is_string($encoded) && $encoded !== '[]' && $encoded !== '{}') {
                return $encoded;
            }
        }

        $summary = $plan->getSummary() ?? 'Die Anfrage wurde ausgefuehrt.';
        $sections = [$summary, ''];
        foreach ($state->all() as $key => $value) {
            $sections[] = sprintf(
                "## %s\n%s",
                $key,
                is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            );
        }

        return implode("\n\n", $sections);
    }

    /**
     * Baut den MessageBag mit optionalem persistierten System-Kontext
     * (Luecke 5: Konversationsverlauf). Ohne Kontext verhaelt sich der
     * Agent-Call identisch zum bisherigen Verhalten.
     */
    private function buildMessageBag(PipelineContext $context, ?string $userPrompt = null): MessageBag
    {
        $messages = [];
        $systemContext = $context->getSystemContext();
        if ($systemContext !== null && $systemContext !== '') {
            $messages[] = Message::forSystem($systemContext);
        }
        $messages[] = Message::ofUser($userPrompt ?? $context->getMessage());

        return new MessageBag(...$messages);
    }
}
