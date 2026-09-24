<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Pipeline;

use App\AI\Pipeline\Capability\CapabilityDecision;
use App\AI\Pipeline\Capability\CapabilityResolverInterface;
use App\AI\Pipeline\Capability\CapabilityResult;
use App\AI\Pipeline\Execution\ExecutionCoordinatorInterface;
use App\AI\Pipeline\Execution\PipelineResult;
use App\AI\Pipeline\Goal\Goal;
use App\AI\Pipeline\Goal\GoalResolverInterface;
use App\AI\Pipeline\Intent\Intent;
use App\AI\Pipeline\Intent\IntentClassifierInterface;
use App\AI\Pipeline\Pipeline;
use App\AI\Pipeline\PipelineContext;
use App\AI\Pipeline\Plan\Plan;
use App\AI\Pipeline\Plan\PlannerInterface;
use App\AI\Pipeline\Plan\Step;
use App\Repository\AgentGoalRepository;
use App\Repository\UserProfileRepository;
use App\AI\Streaming\StreamingPublisher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit-Tests fuer die Pipeline-Orchestrierung (Phasen 1-5).
 *
 * Verifiziert die feste Phasenreihenfolge und die Exit-Gates. Die
 * Regressionssicherung gegen den urspruenglichen Fehler:
 *  - conversation/information -> Dialog, KEINE Capability-Generierung.
 *  - unclear -> clarify, KEINE Capability-Generierung.
 *  - fehlende Capability -> awaitingApproval (HITL), keine Execution.
 *
 * Die Phasen-Implementierungen werden gemockt; es werden keine echten
 * LLM-Aufrufe oder Tools ausgefuehrt.
 *
 * @see docs/architecture/orchestrator-pipeline.md
 */
final class PipelineTest extends TestCase
{
    private GoalResolverInterface&MockObject $goalResolver;
    private IntentClassifierInterface&MockObject $intentClassifier;
    private PlannerInterface&MockObject $planner;
    private CapabilityResolverInterface&MockObject $capabilityResolver;
    private ExecutionCoordinatorInterface&MockObject $execution;
    private AgentGoalRepository&MockObject $agentGoalRepository;
    private UserProfileRepository&MockObject $userProfileRepository;

    protected function setUp(): void
    {
        $this->goalResolver = $this->createMock(GoalResolverInterface::class);
        $this->intentClassifier = $this->createMock(IntentClassifierInterface::class);
        $this->planner = $this->createMock(PlannerInterface::class);
        $this->capabilityResolver = $this->createMock(CapabilityResolverInterface::class);
        $this->execution = $this->createMock(ExecutionCoordinatorInterface::class);
        $this->agentGoalRepository = $this->createMock(AgentGoalRepository::class);
        $this->userProfileRepository = $this->createMock(UserProfileRepository::class);
    }

    public function testConversationExitGateReturnsDialogWithoutCapability(): void
    {
        $this->goalResolver->method('resolve')->willReturn(
            new Goal('g', 'ad-hoc', null, Goal::SOURCE_AD_HOC)
        );
        $this->intentClassifier->method('classify')->willReturn(Intent::Conversation);

        // Planner und CapabilityResolver duerfen fuer Dialog NIE aufgerufen werden.
        $this->planner->expects(self::never())->method('plan');
        $this->capabilityResolver->expects(self::never())->method('resolve');

        $expected = new PipelineResult(PipelineResult::TYPE_DIALOG, 'Hallo');
        $this->execution->expects(self::once())->method('dialog')->willReturn($expected);

        $result = $this->pipeline()->run('moin wer bist du', 'user-1');

        self::assertSame($expected, $result);
    }

    public function testInformationExitGateReturnsDialogWithoutCapability(): void
    {
        $this->goalResolver->method('resolve')->willReturn(
            new Goal('g', 'ad-hoc', null, Goal::SOURCE_AD_HOC)
        );
        $this->intentClassifier->method('classify')->willReturn(Intent::Information);

        $this->planner->expects(self::never())->method('plan');
        $this->capabilityResolver->expects(self::never())->method('resolve');

        $expected = new PipelineResult(PipelineResult::TYPE_DIALOG, 'Info');
        $this->execution->expects(self::once())->method('dialog')->willReturn($expected);

        $result = $this->pipeline()->run('was kannst du', 'user-2');

        self::assertSame($expected, $result);
    }

    public function testUnclearExitGateReturnsClarificationWithoutCapability(): void
    {
        $this->goalResolver->method('resolve')->willReturn(
            new Goal('g', 'ad-hoc', null, Goal::SOURCE_AD_HOC)
        );
        $this->intentClassifier->method('classify')->willReturn(Intent::Unclear);
        $this->planner->method('plan')->willReturn(
            new Plan([new Step(Step::TYPE_CLARIFY, '', [], false, 'Bitte genauer')])
        );

        // CapabilityResolver darf fuer clarify NIE aufgerufen werden.
        $this->capabilityResolver->expects(self::never())->method('resolve');

        $expected = new PipelineResult(PipelineResult::TYPE_CLARIFY, 'Rueckfrage');
        $this->execution->expects(self::once())->method('clarify')->willReturn($expected);
        $this->execution->expects(self::never())->method('execute');

        $result = $this->pipeline()->run('irgendwas', 'user-3');

        self::assertSame($expected, $result);
    }

    public function testMissingCapabilityExitGateReturnsAwaitingApprovalWithoutExecution(): void
    {
        $this->goalResolver->method('resolve')->willReturn(
            new Goal('g', 'ad-hoc', null, Goal::SOURCE_AD_HOC)
        );
        $this->intentClassifier->method('classify')->willReturn(Intent::Task);
        $this->planner->method('plan')->willReturn(
            new Plan([new Step(Step::TYPE_TOOL, 'missing_api', [], true)])
        );
        $this->capabilityResolver->method('resolve')->willReturn(
            new CapabilityResult(CapabilityDecision::Missing, null, null)
        );

        $this->execution->expects(self::never())->method('execute');
        $expected = new PipelineResult(PipelineResult::TYPE_AWAITING_APPROVAL, 'Freigabe', 99);
        $this->execution->expects(self::once())->method('awaitingApproval')->willReturn($expected);

        $result = $this->pipeline()->run('rufe API ab', 'user-4');

        self::assertSame($expected, $result);
    }

    public function testPendingCapabilityExitGateReturnsAwaitingApproval(): void
    {
        $this->goalResolver->method('resolve')->willReturn(
            new Goal('g', 'ad-hoc', null, Goal::SOURCE_AD_HOC)
        );
        $this->intentClassifier->method('classify')->willReturn(Intent::Task);
        $this->planner->method('plan')->willReturn(
            new Plan([new Step(Step::TYPE_TOOL, 'pending_tool', [], true)])
        );
        $this->capabilityResolver->method('resolve')->willReturn(
            new CapabilityResult(CapabilityDecision::Pending, null, 5)
        );

        $this->execution->expects(self::never())->method('execute');
        $expected = new PipelineResult(PipelineResult::TYPE_AWAITING_APPROVAL, 'wartet', 5);
        $this->execution->expects(self::once())->method('awaitingApproval')->willReturn($expected);

        $result = $this->pipeline()->run('sende mail', 'user-5');

        self::assertSame($expected, $result);
    }

    public function testAvailableCapabilityProceedsToExecution(): void
    {
        $this->goalResolver->method('resolve')->willReturn(
            new Goal('g', 'ad-hoc', null, Goal::SOURCE_AD_HOC)
        );
        $this->intentClassifier->method('classify')->willReturn(Intent::Task);
        $this->planner->method('plan')->willReturn(
            new Plan([new Step(Step::TYPE_TOOL, 'weather', ['city' => 'Berlin'])])
        );

        $reference = new \stdClass();
        $this->capabilityResolver->method('resolve')->willReturn(
            new CapabilityResult(CapabilityDecision::Available, $reference, null)
        );

        $expected = new PipelineResult(PipelineResult::TYPE_EXECUTED, 'Wetter: 24 Grad');
        $this->execution->expects(self::once())->method('execute')
            ->with(self::callback(function (PipelineContext $ctx): bool {
                return $ctx->getUserIdentifier() === 'user-6';
            }), self::callback(function (Plan $plan): bool {
                // Step muss die ExecutionReference aus Phase 4 tragen.
                $steps = $plan->getSteps();

                return count($steps) === 1 && $steps[0]->getExecutionReference() !== null;
            }))
            ->willReturn($expected);

        $result = $this->pipeline()->run('Wie ist das Wetter in Berlin?', 'user-6');

        self::assertSame($expected, $result);
    }

    public function testSetupTaskPersistsAgentGoal(): void
    {
        $this->goalResolver->method('resolve')->willReturn(
            new Goal('g', 'ad-hoc', null, Goal::SOURCE_AD_HOC)
        );
        $this->intentClassifier->method('classify')->willReturn(Intent::SetupTask);
        $this->planner->method('plan')->willReturn(
            new Plan([new Step(Step::TYPE_TOOL, 'strategy_document', ['name' => 'Businessplan', 'content' => '...'])], 'Businessplan erstellen')
        );
        $this->capabilityResolver->method('resolve')->willReturn(
            new CapabilityResult(CapabilityDecision::Available)
        );

        $userProfile = new \App\Entity\UserProfile();
        $this->userProfileRepository->method('findOneBy')->willReturn($userProfile);

        // AgentGoal muss persistiert werden (Luecke 2).
        $this->agentGoalRepository->expects(self::once())->method('save')
            ->with(self::callback(function (\App\Entity\AgentGoal $goal): bool {
                $constraints = $goal->getCapabilityConstraints();

                return $goal->getStatus() === 'paused'
                    && $goal->isRequiresApproval() === true
                    && $goal->isApproved() === false
                    && $goal->getTitle() === 'Businessplan erstellen'
                    // Plan-Steps werden als Strategie mitpersistiert (Fix B).
                    && is_array($constraints)
                    && isset($constraints[0]['type'], $constraints[0]['target'])
                    && $constraints[0]['type'] === Step::TYPE_TOOL
                    && $constraints[0]['target'] === 'strategy_document';
            }), true);

        $expected = new PipelineResult(PipelineResult::TYPE_EXECUTED, 'Done');
        $this->execution->expects(self::once())->method('execute')->willReturn($expected);

        $result = $this->pipeline()->run('EVIE soll mein Unternehmen aufbauen', 'user-setup');

        self::assertSame($expected, $result);
    }

    public function testRunWithSessionIdPublishesPhaseProgress(): void
    {
        // Uebergibt der Client eine Session-ID, muessen die Phasen-Events
        // auf /streaming/sessions/{sessionId} publiziert werden. Hier fuer
        // den kurzen Dialog-Pfad (Goal 10%, Intent 25%).
        $publisher = $this->createMock(StreamingPublisher::class);
        $publisher->expects(self::exactly(2))->method('publishProgress')
            ->with(
                'sess-1',
                self::callback(fn (float $p): bool => in_array($p, [10.0, 25.0], true)),
                self::callback(fn (string $m): bool => $m !== '')
            );

        $this->goalResolver->method('resolve')->willReturn(
            new Goal('g', 'ad-hoc', null, Goal::SOURCE_AD_HOC)
        );
        $this->intentClassifier->method('classify')->willReturn(Intent::Conversation);
        $this->execution->method('dialog')->willReturn(
            new PipelineResult(PipelineResult::TYPE_DIALOG, 'Hallo')
        );

        $pipeline = new Pipeline(
            $this->goalResolver,
            $this->intentClassifier,
            $this->planner,
            $this->capabilityResolver,
            $this->execution,
            $this->agentGoalRepository,
            $this->userProfileRepository,
            new NullLogger(),
            $publisher
        );

        $result = $pipeline->run('moin wer bist du', 'user-1', null, 'sess-1');
        self::assertSame(PipelineResult::TYPE_DIALOG, $result->getType());
    }

    public function testRunWithoutSessionIdStaysSilent(): void
    {
        // Ohne Session-ID (interne Aufrufer) darf kein Progress-Event
        // publiziert werden.
        $publisher = $this->createMock(StreamingPublisher::class);
        $publisher->expects(self::never())->method('publishProgress');

        $this->goalResolver->method('resolve')->willReturn(
            new Goal('g', 'ad-hoc', null, Goal::SOURCE_AD_HOC)
        );
        $this->intentClassifier->method('classify')->willReturn(Intent::Conversation);
        $this->execution->method('dialog')->willReturn(
            new PipelineResult(PipelineResult::TYPE_DIALOG, 'Hallo')
        );

        $pipeline = new Pipeline(
            $this->goalResolver,
            $this->intentClassifier,
            $this->planner,
            $this->capabilityResolver,
            $this->execution,
            $this->agentGoalRepository,
            $this->userProfileRepository,
            new NullLogger(),
            $publisher
        );

        $result = $pipeline->run('moin wer bist du', 'user-1');
        self::assertSame(PipelineResult::TYPE_DIALOG, $result->getType());
    }

    private function pipeline(): Pipeline
    {
        return new Pipeline(
            $this->goalResolver,
            $this->intentClassifier,
            $this->planner,
            $this->capabilityResolver,
            $this->execution,
            $this->agentGoalRepository,
            $this->userProfileRepository,
            new NullLogger()
        );
    }
}
