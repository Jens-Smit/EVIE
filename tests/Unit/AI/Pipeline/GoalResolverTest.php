<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Pipeline;

use App\AI\Pipeline\Goal\Goal;
use App\AI\Pipeline\Goal\GoalResolver;
use App\AI\Pipeline\PipelineContext;
use App\Entity\AgentGoal;
use App\Repository\AgentGoalRepository;
use App\Tests\Stub\StubDeferredResult;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\PlatformInterface;

/**
 * Unit-Tests fuer den GoalResolver (Phase 1).
 *
 * Verifiziert die Quellen-Reihenfolge: aktives AgentGoal hat Vorrang vor
 * ad-hoc-LLM, und bei LLM-Ausfall wird ein generisches ad-hoc Goal
 * geliefert, damit die Pipeline ohne Capability-Generierung fortgesetzt
 * wird. Nutzt StubDeferredResult fuer deterministische LLM-Pfade.
 *
 * @see docs/architecture/orchestrator-pipeline.md Phase 1
 */
final class GoalResolverTest extends TestCase
{
    private AgentGoalRepository&MockObject $goalRepo;
    private PlatformInterface&MockObject $platform;

    protected function setUp(): void
    {
        $this->goalRepo = $this->createMock(AgentGoalRepository::class);
        $this->platform = $this->createMock(PlatformInterface::class);
    }

    public function testActiveAgentGoalIsPreferred(): void
    {
        $agentGoal = new AgentGoal();
        $agentGoal->setTitle('100k EUR Jahresumsatz');
        $agentGoal->setSuccessMetric('>= 100000 EUR');

        $this->goalRepo->method('findActiveByUser')->willReturn([$agentGoal]);

        $resolver = new GoalResolver($this->goalRepo, $this->platform, new NullLogger());
        $goal = $resolver->resolve(PipelineContext::create('hallo', 'user-1'));

        self::assertSame(Goal::SOURCE_AGENT_GOAL, $goal->getSource());
        self::assertSame('100k EUR Jahresumsatz', $goal->getDescription());
        self::assertSame('>= 100000 EUR', $goal->getSuccessMetric());
        // Plattform darf bei vorhandenem AgentGoal nicht angerufen werden.
        $this->platform->expects(self::never())->method('invoke');
    }

    public function testAdHocGoalFromLlmWhenNoAgentGoal(): void
    {
        $this->goalRepo->method('findActiveByUser')->willReturn([]);
        $this->platform->method('invoke')->willReturn(StubDeferredResult::withText('Wetter abfragen'));

        $resolver = new GoalResolver($this->goalRepo, $this->platform, new NullLogger());
        $goal = $resolver->resolve(PipelineContext::create('Wie ist das Wetter?', 'user-2'));

        self::assertSame(Goal::SOURCE_AD_HOC, $goal->getSource());
        self::assertSame('Wetter abfragen', $goal->getDescription());
        self::assertNull($goal->getSuccessMetric());
    }

    public function testAdHocFallbackToMessageOnLlmFailure(): void
    {
        $this->goalRepo->method('findActiveByUser')->willReturn([]);
        $this->platform->method('invoke')->willThrowException(new \RuntimeException('Mistral timeout'));

        $resolver = new GoalResolver($this->goalRepo, $this->platform, new NullLogger());
        $goal = $resolver->resolve(PipelineContext::create('konkreter Text', 'user-3'));

        self::assertSame(Goal::SOURCE_AD_HOC, $goal->getSource());
        self::assertSame('konkreter Text', $goal->getDescription());
    }

    public function testAdHocFallbackWhenLlmReturnsEmpty(): void
    {
        $this->goalRepo->method('findActiveByUser')->willReturn([]);
        $this->platform->method('invoke')->willReturn(StubDeferredResult::withText('   '));

        $resolver = new GoalResolver($this->goalRepo, $this->platform, new NullLogger());
        $goal = $resolver->resolve(PipelineContext::create('meine Anfrage', 'user-4'));

        self::assertSame('meine Anfrage', $goal->getDescription());
    }
}
