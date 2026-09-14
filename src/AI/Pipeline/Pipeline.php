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

    public function __construct(
        GoalResolverInterface $goalResolver,
        IntentClassifierInterface $intentClassifier,
        PlannerInterface $planner,
        CapabilityResolverInterface $capabilityResolver,
        ExecutionCoordinatorInterface $executionCoordinator,
        AgentGoalRepository $agentGoalRepository,
        UserProfileRepository $userProfileRepository
    ) {
        $this->goalResolver = $goalResolver;
        $this->intentClassifier = $intentClassifier;
        $this->planner = $planner;
        $this->capabilityResolver = $capabilityResolver;
        $this->executionCoordinator = $executionCoordinator;
        $this->agentGoalRepository = $agentGoalRepository;
        $this->userProfileRepository = $userProfileRepository;
    }

    public function run(string $message, string $userIdentifier): PipelineResult
    {
        $context = PipelineContext::create($message, $userIdentifier);

        // Phase 1 — Goal
        $context = $context->withGoal($this->goalResolver->resolve($context));

        // Phase 2 — Intent (Exit-Gate: Dialog)
        $intent = $this->intentClassifier->classify($context);
        $context = $context->withIntent($intent);
        if ($intent->isDialog()) {
            return $this->executionCoordinator->dialog($context);
        }

        // Phase 3 — Plan (Exit-Gate: clarify)
        $plan = $this->planner->plan($context, $intent);
        if ($plan->isClarification()) {
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
        $goal->setStatus('paused');
        $goal->setRequiresApproval(true);
        $goal->setIsApproved(false);
        $goal->setUserProfile($userProfile);

        $this->agentGoalRepository->save($goal, true);
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
