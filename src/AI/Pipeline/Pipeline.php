<?php

declare(strict_types=1);

namespace App\AI\Pipeline;

use App\AI\Pipeline\Capability\CapabilityResolverInterface;
use App\AI\Pipeline\Execution\ExecutionCoordinatorInterface;
use App\AI\Pipeline\Execution\PipelineResult;
use App\AI\Pipeline\Goal\GoalResolverInterface;
use App\AI\Pipeline\Intent\Intent;
use App\AI\Pipeline\Intent\IntentClassifierInterface;
use App\AI\Pipeline\Plan\Plan;
use App\AI\Pipeline\Plan\PlannerInterface;
use App\AI\Pipeline\Plan\Step;
use App\Entity\AgentGoal;
use App\Repository\AgentGoalRepository;
use App\Repository\UserProfileRepository;
use Psr\Log\LoggerInterface;

/**
 * Orchestriert die fuenf Phasen Goal -> Intent -> Plan -> Capability ->
 * Execution (Blueprint §4.A, §5). Die Klasse enthaelt keine eigene
 * Fachlogik; sie verdrahtet die Phasen-Implementierungen in der
 * festen Reihenfolge und wendet die Exit-Gates an.
 *
 * Exit-Gates:
 *  - Phase 2 (conversation/information) -> direkte Dialog-Antwort
 *  - Phase 3 (clarify)                  -> Rueckfrage, keine Capability
 *  - Phase 4 (missing/pending)          -> HITL, Pipeline pausiert
 *
 * @see docs/architecture/orchestrator-pipeline.md
 */
final class Pipeline implements PipelineInterface
{
    private GoalResolverInterface $goalResolver;
    private IntentClassifierInterface $intentClassifier;
    private PlannerInterface $planner;
    private CapabilityResolverInterface $capabilityResolver;
    private ExecutionCoordinatorInterface $executionCoordinator;
    private AgentGoalRepository $agentGoalRepository;
    private UserProfileRepository $userProfileRepository;
    private LoggerInterface $logger;

    public function __construct(
        GoalResolverInterface $goalResolver,
        IntentClassifierInterface $intentClassifier,
        PlannerInterface $planner,
        CapabilityResolverInterface $capabilityResolver,
        ExecutionCoordinatorInterface $executionCoordinator,
        AgentGoalRepository $agentGoalRepository,
        UserProfileRepository $userProfileRepository,
        LoggerInterface $logger
    ) {
        $this->goalResolver = $goalResolver;
        $this->intentClassifier = $intentClassifier;
        $this->planner = $planner;
        $this->capabilityResolver = $capabilityResolver;
        $this->executionCoordinator = $executionCoordinator;
        $this->agentGoalRepository = $agentGoalRepository;
        $this->userProfileRepository = $userProfileRepository;
        $this->logger = $logger;
    }

    public function run(string $message, string $userIdentifier, ?string $systemContext = null): PipelineResult
    {
        $this->logger->debug('Pipeline.run: Start', [
            'user_identifier' => $userIdentifier,
            'message' => $message,
        ]);
        $context = PipelineContext::create($message, $userIdentifier, $systemContext);

        // Phase 1 — Goal
        $context = $context->withGoal($this->goalResolver->resolve($context));
        $this->logger->debug('Pipeline.run: Phase 1 Goal aufgeloest', [
            'goal' => $context->getGoal() !== null ? $context->getGoal()->getIdentifier() : null,
            'goal_source' => $context->getGoal() !== null ? $context->getGoal()->getSource() : null,
        ]);

        // Phase 2 — Intent (Exit-Gate: Dialog)
        $intent = $this->intentClassifier->classify($context);
        $context = $context->withIntent($intent);
        $this->logger->debug('Pipeline.run: Phase 2 Intent klassifiziert', [
            'intent' => $intent->name,
        ]);
        if ($intent->isDialog()) {
            $this->logger->debug('Pipeline.run: Exit-Gate Dialog (Intent ist dialogorientiert)');
            return $this->executionCoordinator->dialog($context);
        }

        // Phase 3 — Plan (Exit-Gate: clarify)
        $plan = $this->planner->plan($context, $intent);
        $this->logger->debug('Pipeline.run: Phase 3 Plan erstellt', [
            'is_clarification' => $plan->isClarification(),
            'steps' => count($plan->getSteps()),
        ]);
        if ($plan->isClarification()) {
            $this->logger->debug('Pipeline.run: Exit-Gate clarify', [
                'reason' => $plan->getSteps()[0]->getReason(),
            ]);
            return $this->executionCoordinator->clarify($context, $plan);
        }

        // Luecke 2: Bei SetupTask-Intent wird der Plan als persistentes
        // AgentGoal gespeichert, damit EVIE die mehrstufige Aufgabe ueber
        // mehrere Dialogrunden/Messenger-Ausfuehrungen autonom abarbeitet.
        if ($intent === Intent::SetupTask) {
            $this->persistSetupTaskGoal($context, $plan);
        }

        // Phase 4 — Capability (Exit-Gate: HITL)
        $resolvedPlan = $plan;
        foreach ($plan->getSteps() as $step) {
            $result = $this->capabilityResolver->resolve($step, $context);
            $decision = $result->getDecision();
            if ($decision->isMissing() || $decision->isPending()) {
                return $this->executionCoordinator->awaitingApproval($context, $result);
            }

            $reference = $result->getExecutionReference();
            if ($reference !== null) {
                $resolvedPlan = $this->replaceStep($resolvedPlan, $step, $step->withExecutionReference($reference));
            }
        }

        // Phase 5 — Execution
        return $this->executionCoordinator->execute($context, $resolvedPlan);
    }

    /**
     * Persistiert den Plan als AgentGoal (Luecke 2), damit EVIE die
     * mehrstufige Setup-Aufgabe autonom ueber RunAgentGoalHandler
     * abarbeitet. Das Goal ist paused + requiresApproval=true (HITL).
     */
    private function persistSetupTaskGoal(PipelineContext $context, Plan $plan): void
    {
        $userProfile = $this->userProfileRepository->findOneBy([
            'userIdentifier' => $context->getUserIdentifier(),
        ]);
        if ($userProfile === null) {
            return;
        }

        $summary = $plan->getSummary() ?? $context->getMessage();
        $goal = new AgentGoal();
        $goal->setUserIdentifier($context->getUserIdentifier());
        $goal->setTitle(mb_substr($summary, 0, 255));
        $goal->setDescription($context->getMessage());
        // Plan-Steps als Strategie persistieren, damit die Ordnungs- und
        // Delegationsinformation zwischen Pipeline-Lauf und Worker-
        // Ausfuehrung erhalten bleibt (Luecke 2, Blueprint §5).
        $goal->setCapabilityConstraints($this->extractPlanSteps($plan));
        $goal->setStatus('paused');
        $goal->setRequiresApproval(true);
        $goal->setIsApproved(false);
        $goal->setUserProfile($userProfile);

        $this->agentGoalRepository->save($goal, true);
    }

    /**
     * Serialisiert die Plan-Steps fuer die Persistenz im AgentGoal
     * (capabilityConstraints-Feld). Enthaelt type, target, parameters und
     * reason jedes Schritts; die ExecutionReference ist bewusst nicht
     * Teil der Serialisierung (nicht DB-faehig).
     *
     * @return list<array{type: string, target: string, parameters: array<string, mixed>, reason: string|null}>
     */
    private function extractPlanSteps(Plan $plan): array
    {
        $steps = [];
        foreach ($plan->getSteps() as $step) {
            $steps[] = [
                'type' => $step->getType(),
                'target' => $step->getTarget(),
                'parameters' => $step->getParameters(),
                'reason' => $step->getReason(),
            ];
        }

        return $steps;
    }

    /**
     * Ersetzt einen Schritt innerhalb eines Plans durch eine neue Instanz
     * (z.B. mit gesetzter ExecutionReference) und liefert einen neuen Plan.
     */
    private function replaceStep(Plan $plan, Step $oldStep, Step $newStep): Plan
    {
        $steps = [];
        foreach ($plan->getSteps() as $step) {
            $steps[] = $step === $oldStep ? $newStep : $step;
        }

        return new Plan($steps, $plan->getSummary());
    }
}
