<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Pipeline;

use App\AI\Agent\SubAgentFactoryInterface;
use App\AI\Pipeline\Capability\CapabilityResolver;
use App\AI\Pipeline\Plan\Step;
use App\AI\Pipeline\PipelineContext;
use App\AI\Skills\Tool\ToolRegistry;
use App\AI\Skills\ToolDefinitionGenerator;
use App\Entity\ToolDefinition;
use App\Event\PendingToolApprovalEvent;
use App\Repository\ToolDefinitionRepository;
use App\Tests\Stub\StubAgent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Unit-Tests fuer den CapabilityResolver (Phase 4).
 *
 * Verifiziert die Lookup-Reihenfolge fuer statische/dynamische Tools und
 * Sub-Agenten und dass bei fehlender Faehigkeit (needs_capability) ein
 * PendingToolApprovalEvent ausgeloest wird (HITL). Statische Tools und
 * Sub-Agenten liefern Available; nicht vorhandene ohne Flag liefern
 * Missing OHNE Generierung.
 *
 * @see docs/architecture/orchestrator-pipeline.md Phase 4
 */
final class CapabilityResolverTest extends TestCase
{
    private ToolRegistry $toolRegistry;
    private ToolDefinitionRepository&MockObject $toolDefinitionRepo;
    private SubAgentFactoryInterface&MockObject $subAgentFactory;
    private ToolDefinitionGenerator&MockObject $toolGenerator;
    private EventDispatcherInterface&MockObject $dispatcher;

    protected function setUp(): void
    {
        $this->toolRegistry = new ToolRegistry([
            new class implements \App\AI\Skills\Tool\ToolInterface {
                public function getName(): string
                {
                    return 'weather';
                }

                public function getDescription(): string
                {
                    return '';
                }

                public function __invoke(array $parameters = []): array
                {
                    return ['temp' => 24];
                }
            },
        ]);
        $this->toolDefinitionRepo = $this->createMock(ToolDefinitionRepository::class);
        $this->subAgentFactory = $this->createMock(SubAgentFactoryInterface::class);
        $this->toolGenerator = $this->createMock(ToolDefinitionGenerator::class);
        $this->dispatcher = $this->createMock(EventDispatcherInterface::class);
    }

    public function testClarifyStepIsAlwaysAvailable(): void
    {
        $resolver = $this->buildResolver();
        $result = $resolver->resolve(
            new Step(Step::TYPE_CLARIFY, 'Rueckfrage', [], false),
            $this->context()
        );

        self::assertTrue($result->getDecision()->isAvailable());
    }

    public function testStaticToolIsAvailable(): void
    {
        $resolver = $this->buildResolver();
        $result = $resolver->resolve(
            new Step(Step::TYPE_TOOL, 'weather', ['city' => 'Berlin']),
            $this->context()
        );

        self::assertTrue($result->getDecision()->isAvailable());
        // Available ohne ExecutionReference: die Ausfuehrung laeuft ueber
        // den nativen Agent-Loop, der die Tools aus seiner Toolbox kennt.
        self::assertNull($result->getExecutionReference());
    }

    public function testNativeAsToolIsAvailableWithoutToolInterface(): void
    {
        // Regression: ein natives #[AsTool]-Tool (z. B. Tavily) implementiert
        // ToolInterface nicht. resolveTool() darf get() nicht aufrufen, da
        // dies sonst wirft; die Ausfuehrung laeuft nativ ueber den Agent-Loop.
        $registry = new ToolRegistry([new CapabilityNativeAsToolStub()]);
        $resolver = new CapabilityResolver(
            $registry,
            $this->toolDefinitionRepo,
            $this->subAgentFactory,
            $this->toolGenerator,
            $this->dispatcher,
            new NullLogger()
        );

        $result = $resolver->resolve(
            new Step(Step::TYPE_TOOL, 'data_analyzer', []),
            $this->context()
        );

        self::assertTrue($result->getDecision()->isAvailable());
        self::assertNull($result->getExecutionReference());
    }

    public function testApprovedDynamicToolIsAvailable(): void
    {
        $definition = $this->buildToolDefinition('custom_api', 'approved');
        $this->toolDefinitionRepo->method('findOneByNameForUser')->willReturn($definition);

        $resolver = $this->buildResolver();
        $result = $resolver->resolve(
            new Step(Step::TYPE_TOOL, 'custom_api', []),
            $this->context()
        );

        self::assertTrue($result->getDecision()->isAvailable());
        self::assertSame($definition, $result->getExecutionReference());
    }

    public function testPendingDynamicToolIsPendingWithoutGeneration(): void
    {
        $definition = $this->buildToolDefinition('custom_api', 'pending');
        $this->toolDefinitionRepo->method('findOneByNameForUser')->willReturn($definition);
        $this->toolGenerator->expects(self::never())->method('generateToolDefinition');
        $this->dispatcher->expects(self::never())->method('dispatch');

        $resolver = $this->buildResolver();
        $result = $resolver->resolve(
            new Step(Step::TYPE_TOOL, 'custom_api', [], true),
            $this->context()
        );

        self::assertTrue($result->getDecision()->isPending());
        self::assertSame($definition->getId(), $result->getToolDefinitionId());
    }

    public function testMissingToolWithoutNeedsCapabilityIsMissingWithoutGeneration(): void
    {
        $this->toolDefinitionRepo->method('findOneByNameForUser')->willReturn(null);
        $this->toolGenerator->expects(self::never())->method('generateToolDefinition');
        $this->dispatcher->expects(self::never())->method('dispatch');

        $resolver = $this->buildResolver();
        $result = $resolver->resolve(
            new Step(Step::TYPE_TOOL, 'nonexistent', [], false),
            $this->context()
        );

        self::assertTrue($result->getDecision()->isMissing());
    }

    public function testMissingToolWithNeedsCapabilityGeneratesAndDispatchesHitl(): void
    {
        $this->toolDefinitionRepo->method('findOneByNameForUser')->willReturn(null);
        $generated = $this->buildToolDefinition('new_api', 'pending');
        $this->toolGenerator->expects(self::once())->method('generateToolDefinition')->willReturn($generated);
        $this->dispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(fn ($e) => $e instanceof PendingToolApprovalEvent
                && $e->getToolDefinition() === $generated));

        $resolver = $this->buildResolver();
        $result = $resolver->resolve(
            new Step(Step::TYPE_TOOL, 'new_api', [], true, 'API zum Abrufen'),
            $this->context()
        );

        self::assertTrue($result->getDecision()->isPending());
        self::assertSame($generated->getId(), $result->getToolDefinitionId());
    }

    public function testAvailableSubAgentIsAvailable(): void
    {
        $agent = new StubAgent('ok');
        $this->subAgentFactory->method('getAvailableSubAgents')->willReturn(['data_analyst' => $agent]);

        $resolver = $this->buildResolver();
        $result = $resolver->resolve(
            new Step(Step::TYPE_SUBAGENT, 'data_analyst', []),
            $this->context()
        );

        self::assertTrue($result->getDecision()->isAvailable());
        self::assertSame($agent, $result->getExecutionReference());
    }

    public function testMissingSubAgentIsMissingWithoutGeneration(): void
    {
        $this->subAgentFactory->method('getAvailableSubAgents')->willReturn([]);
        $this->toolGenerator->expects(self::never())->method('generateToolDefinition');
        $this->dispatcher->expects(self::never())->method('dispatch');

        $resolver = $this->buildResolver();
        $result = $resolver->resolve(
            new Step(Step::TYPE_SUBAGENT, 'nonexistent_agent', [], true),
            $this->context()
        );

        self::assertTrue($result->getDecision()->isMissing());
    }

    private function buildResolver(): CapabilityResolver
    {
        return new CapabilityResolver(
            $this->toolRegistry,
            $this->toolDefinitionRepo,
            $this->subAgentFactory,
            $this->toolGenerator,
            $this->dispatcher,
            new NullLogger()
        );
    }

    private function context(): PipelineContext
    {
        return PipelineContext::create('anfrage', 'user-1');
    }

    private function buildToolDefinition(string $name, string $status): ToolDefinition
    {
        $definition = new ToolDefinition();
        $definition->setName($name);
        $definition->setStatus($status);

        return $definition;
    }
}

#[\Symfony\AI\Agent\Toolbox\Attribute\AsTool('data_analyzer', 'Analysiert Daten.')]
final class CapabilityNativeAsToolStub
{
    public function __invoke(array $data): string
    {
        return '';
    }
}
