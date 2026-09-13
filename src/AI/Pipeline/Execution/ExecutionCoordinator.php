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
 * Die Ausfuehrung (execute) nutzt die native Agent-Loop des
 * ai.agent.orchestrator (Toolbox -> ToolCallRequested -> HitlListener ->
 * SecurityGuard -> Executor); der Coordinator ruft den Agenten auf und
 * liefert dessen finale Textantwort. Die Formatierungsmethoden
 * (dialog/clarify/awaitingApproval) erzeugen die User-Antwort fuer die
 * Exit-Gates der Phasen 2-4, ohne eine Capability zu generieren oder eine
 * Aktion auszuloesen.
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

    public function __construct(
        #[Autowire(service: 'ai.agent.orchestrator')]
        AgentInterface $orchestratorAgent,
        LlmRetryExecutor $llmRetryExecutor,
        UrlGeneratorInterface $urlGenerator,
        LoggerInterface $logger
    ) {
        $this->orchestratorAgent = $orchestratorAgent;
        $this->llmRetryExecutor = $llmRetryExecutor;
        $this->urlGenerator = $urlGenerator;
        $this->logger = $logger;
    }

    public function dialog(PipelineContext $context): PipelineResult
    {
        // Phase 2 Exit-Gate: Konversation/Information. Der native
        // Orchestrator-Agent antwortet dialogorientiert (sein Prompt
        // erzeugt type 'dialog'); keine Tool-Generierung.
        try {
            $messages = new MessageBag(Message::ofUser($context->getMessage()));
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
                "Ich habe ein neues Werkzeug fuer deine Anfrage entworfen, das vor der "
                . "Ausfuehrung freigegeben werden muss.\n\n"
                . "\xF0\x9F\x91\x89 **Freigeben/Ablehnen:** %s (Tool-ID %d)\n\n"
                . "Sobald du das Werkzeug freigibst, fahre ich mit deiner Anfrage fort.",
                $toolsUrl,
                $toolDefinitionId
            );

            return new PipelineResult(PipelineResult::TYPE_AWAITING_APPROVAL, $content, $toolDefinitionId);
        }

        // Missing ohne generierte Definition: kein Tool moeglich.
        return new PipelineResult(
            PipelineResult::TYPE_AWAITING_APPROVAL,
            'Ich habe aktuell kein passendes Werkzeug fuer diese Anfrage und konnte '
            . 'auch keines entwerfen. Kannst du die Anfrage praeziser stellen oder '
            . 'auf eine bestaehende Faehigkeit beziehen?'
        );
    }

    public function execute(PipelineContext $context, Plan $plan): PipelineResult
    {
        // Phase 5: Ausfuehrung eines freigegebenen Plans ueber die native
        // Agent-Loop. Der native Orchestrator-Agent uebernimmt Tool-Calling,
        // HITL (ToolCallRequested -> HitlListener -> SecurityGuard) und
        // Audit. Wir reichern den Prompt mit dem Plan an, damit der Agent
        // die geplanten Schritte priorisiert, und liefern seine finale
        // Textantwort.
        try {
            $prompt = $this->buildExecutionPrompt($context, $plan);
            $messages = new MessageBag(Message::ofUser($prompt));
            $result = $this->llmRetryExecutor->callAgentWithRetry($this->orchestratorAgent, $messages);
            $content = $result->getContent();

            if (!is_string($content) || $content === '') {
                $content = 'Die Anfrage wurde ausgefuehrt.';
            }

            return new PipelineResult(PipelineResult::TYPE_EXECUTED, $content);
        } catch (\Exception $e) {
            $this->logger->error('ExecutionCoordinator::execute fehlgeschlagen: ' . $e->getMessage());

            return new PipelineResult(
                PipelineResult::TYPE_ERROR,
                'Bei der Ausfuehrung ist ein Fehler aufgetreten: ' . $e->getMessage()
            );
        }
    }

    private function buildExecutionPrompt(PipelineContext $context, Plan $plan): string
    {
        $summary = $plan->getSummary() ?? $context->getMessage();
        $stepDescriptions = [];
        foreach ($plan->getSteps() as $step) {
            $stepDescriptions[] = sprintf(
                '- %s: %s%s',
                $step->getType(),
                $step->getTarget(),
                $step->getReason() !== null ? ' (' . $step->getReason() . ')' : ''
            );
        }
        $stepsText = implode("\n", $stepDescriptions);

        return "Fuehre die folgende Anfrage mit den geplanten Schritten aus. "
            . "Nutze die verfuegbaren Werkzeuge/Sub-Agenten und liefere das Ergebnis.\n\n"
            . "Anfrage: " . $context->getMessage() . "\n\n"
            . "Plan: " . $summary . "\n"
            . "Schritte:\n" . $stepsText;
    }
}
