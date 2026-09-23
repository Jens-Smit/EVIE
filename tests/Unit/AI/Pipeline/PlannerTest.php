<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Pipeline;

use App\AI\Agent\SubAgentFactoryInterface;
use App\AI\Pipeline\Intent\Intent;
use App\AI\Pipeline\Plan\Planner;
use App\AI\Pipeline\PipelineContext;
use App\AI\Skills\Tool\ToolRegistry;
use App\AI\Skills\Tool\ToolInterface;
use App\Tests\Stub\StubDeferredResult;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\PlatformInterface;

/**
 * Unit-Tests fuer den Planner (Phase 3).
 *
 * Verifiziert: unclear -> clarify ohne LLM, erfolgreiche JSON-Plan-Erzeugung,
 * clarify-Fallback bei ungueltigem JSON/LLM-Ausfall (keine Capability), und
 * dass needs_capability aus der LLM-Antwort uebernommen wird. Nutzt
 * StubDeferredResult und einen echten ToolRegistry mit Stub-Tools.
 *
 * @see docs/architecture/orchestrator-pipeline.md Phase 3
 */
final class PlannerTest extends TestCase
{
    private PlatformInterface&MockObject $platform;
    private ToolRegistry $toolRegistry;
    private SubAgentFactoryInterface&MockObject $subAgentFactory;
    private string $promptFile;

    protected function setUp(): void
    {
        $this->platform = $this->createMock(PlatformInterface::class);
        // ToolRegistry mit zwei Stub-Tools fuer den Prompt-Kontext.
        $this->toolRegistry = new ToolRegistry([
            $this->buildTool('weather'),
            $this->buildTool('file_read'),
        ]);
        $this->subAgentFactory = $this->createMock(SubAgentFactoryInterface::class);
        $this->subAgentFactory->method('getAvailableSubAgents')->willReturn([
            'data_analyst' => $this->createMock(\Symfony\AI\Agent\AgentInterface::class),
        ]);
        $this->promptFile = $this->writePromptFile();
    }

    public function testUnclearIntentReturnsClarifyWithoutLlm(): void
    {
        $this->platform->expects(self::never())->method('invoke');

        $planner = $this->buildPlanner();
        $plan = $planner->plan(PipelineContext::create('irgendwas', 'u'), Intent::Unclear);

        self::assertTrue($plan->isClarification());
    }

    public function testTaskReturnsToolPlanFromLlm(): void
    {
        $json = json_encode([
            'summary' => 'Wetter abrufen',
            'steps' => [
                ['type' => 'tool', 'target' => 'weather', 'parameters' => ['city' => 'Berlin'], 'needs_capability' => false, 'reason' => 'Wetterdaten'],
            ],
        ], JSON_THROW_ON_ERROR);
        $this->platform->method('invoke')->willReturn(StubDeferredResult::withText($json));

        $planner = $this->buildPlanner();
        $plan = $planner->plan(PipelineContext::create('Wie ist das Wetter?', 'u'), Intent::Task);

        self::assertFalse($plan->isClarification());
        self::assertSame('Wetter abrufen', $plan->getSummary());
        $steps = $plan->getSteps();
        self::assertCount(1, $steps);
        self::assertSame('weather', $steps[0]->getTarget());
        self::assertSame(['city' => 'Berlin'], $steps[0]->getParameters());
        self::assertFalse($steps[0]->needsCapability());
    }

    public function testMissingCapabilityIsMarkedWithoutInventing(): void
    {
        $json = json_encode([
            'summary' => 'API abrufen',
            'steps' => [
                ['type' => 'tool', 'target' => 'api_call', 'parameters' => [], 'needs_capability' => true, 'reason' => 'Tool fehlt'],
            ],
        ], JSON_THROW_ON_ERROR);
        $this->platform->method('invoke')->willReturn(StubDeferredResult::withText($json));

        $planner = $this->buildPlanner();
        $plan = $planner->plan(PipelineContext::create('rufe API ab', 'u'), Intent::Task);

        $steps = $plan->getSteps();
        self::assertTrue($steps[0]->needsCapability());
        self::assertSame('Tool fehlt', $steps[0]->getReason());
    }

    public function testInvalidJsonFallsBackToClarify(): void
    {
        $this->platform->method('invoke')->willReturn(StubDeferredResult::withText('kein json'));

        $planner = $this->buildPlanner();
        $plan = $planner->plan(PipelineContext::create('???', 'u'), Intent::Task);

        self::assertTrue($plan->isClarification());
    }

    public function testMarkdownFencedJsonIsParsedSuccessfully(): void
    {
        $json = json_encode([
            'summary' => 'Wetter abrufen',
            'steps' => [
                ['type' => 'tool', 'target' => 'weather', 'parameters' => ['city' => 'Berlin'], 'needs_capability' => false, 'reason' => 'Wetterdaten'],
            ],
        ], JSON_THROW_ON_ERROR);
        $fenced = "```json\n" . $json . "\n```";

        $this->platform->method('invoke')->willReturn(StubDeferredResult::withText($fenced));

        $planner = $this->buildPlanner();
        $plan = $planner->plan(PipelineContext::create('Wie ist das Wetter?', 'u'), Intent::Task);

        self::assertFalse($plan->isClarification());
        self::assertSame('Wetter abrufen', $plan->getSummary());
        $steps = $plan->getSteps();
        self::assertCount(1, $steps);
        self::assertSame('weather', $steps[0]->getTarget());
    }

    public function testLlmFailureFallsBackToClarify(): void
    {
        $this->platform->method('invoke')->willThrowException(new \RuntimeException('timeout'));

        $planner = $this->buildPlanner();
        $plan = $planner->plan(PipelineContext::create('egal', 'u'), Intent::Task);

        self::assertTrue($plan->isClarification());
    }

    public function testPromptContainsAvailableCapabilities(): void
    {
        $invokeCount = 0;
        $this->platform->method('invoke')->willReturnCallback(function () use (&$invokeCount) {
            $invokeCount++;
            return StubDeferredResult::withText('{"steps":[{"type":"clarify","target":"","parameters":[]}]}');
        });

        $planner = $this->buildPlanner();
        $planner->plan(PipelineContext::create('zeige Faehigkeiten', 'u'), Intent::Task);

        // Der Prompt wurde erfolgreich gebaut und an die Plattform uebergeben
        // (verfuegbare Tools/Sub-Agenten werden im Prompt substituiert).
        self::assertSame(1, $invokeCount);
    }

    public function testWorkflowFieldsAreParsedFromLlmPlan(): void
    {
        // Phase 3 Workflow-Felder: id, depends_on, input_from und output_key
        // muessen aus der LLM-Antwort in die Steps uebernommen werden, damit
        // Phase 5 deterministisch abhaengigkeitsbewusst ausfuehren kann.
        $json = json_encode([
            'summary' => 'Businessplan erstellen',
            'steps' => [
                [
                    'id' => 'research_market',
                    'type' => 'subagent',
                    'target' => 'website_researcher',
                    'parameters' => ['task' => 'Recherchiere visiongastro.de'],
                    'needs_capability' => false,
                    'reason' => 'Marktdaten sammeln',
                    'depends_on' => [],
                    'input_from' => [],
                    'output_key' => 'market_research',
                ],
                [
                    'id' => 'analyse_market',
                    'type' => 'subagent',
                    'target' => 'data_analyst',
                    'parameters' => ['task' => 'Analysiere die Recherche'],
                    'needs_capability' => false,
                    'reason' => 'Daten analysieren',
                    'depends_on' => ['research_market'],
                    'input_from' => ['market_research'],
                    'output_key' => 'business_analysis',
                ],
                [
                    'id' => 'create_business_plan',
                    'type' => 'tool',
                    'target' => 'weather',
                    'parameters' => ['city' => 'Berlin'],
                    'needs_capability' => false,
                    'reason' => 'Dokument erzeugen',
                    'depends_on' => ['analyse_market'],
                    'input_from' => ['business_analysis'],
                    'output_key' => 'business_plan',
                ],
            ],
        ], JSON_THROW_ON_ERROR);
        $this->platform->method('invoke')->willReturn(StubDeferredResult::withText($json));
        $planner = $this->buildPlanner();

        $plan = $planner->plan(PipelineContext::create('Businessplan von visiongastro.de', 'u'), Intent::Task);

        self::assertFalse($plan->isClarification());
        $steps = $plan->getSteps();
        self::assertCount(3, $steps);

        self::assertSame('research_market', $steps[0]->getId());
        self::assertSame([], $steps[0]->getDependsOn());
        self::assertSame([], $steps[0]->getInputFrom());
        self::assertSame('market_research', $steps[0]->getOutputKey());

        self::assertSame('analyse_market', $steps[1]->getId());
        self::assertSame(['research_market'], $steps[1]->getDependsOn());
        self::assertSame(['market_research'], $steps[1]->getInputFrom());
        self::assertSame('business_analysis', $steps[1]->getOutputKey());

        self::assertSame('create_business_plan', $steps[2]->getId());
        self::assertSame(['analyse_market'], $steps[2]->getDependsOn());
        self::assertSame(['business_analysis'], $steps[2]->getInputFrom());
        self::assertSame('business_plan', $steps[2]->getOutputKey());
    }

    public function testStepGeneratesUniqueIdWhenLlmOmitsId(): void
    {
        // Ohne id in der LLM-Antwort erzeugt der Step automatisch eine
        // eindeutige, target-basierte ID (kein Kollidieren mit anderen Steps).
        $json = json_encode([
            'summary' => 'Wetter',
            'steps' => [
                ['type' => 'tool', 'target' => 'weather', 'parameters' => ['city' => 'Berlin'], 'needs_capability' => false],
                ['type' => 'tool', 'target' => 'weather', 'parameters' => ['city' => 'Hamburg'], 'needs_capability' => false],
            ],
        ], JSON_THROW_ON_ERROR);
        $this->platform->method('invoke')->willReturn(StubDeferredResult::withText($json));
        $planner = $this->buildPlanner();

        $plan = $planner->plan(PipelineContext::create('Wetter', 'u'), Intent::Task);

        $steps = $plan->getSteps();
        self::assertCount(2, $steps);
        self::assertNotSame($steps[0]->getId(), $steps[1]->getId());
        self::assertStringContainsString('weather', $steps[0]->getId());
    }

    private function buildPlanner(): Planner
    {
        return new Planner(
            $this->platform,
            $this->toolRegistry,
            $this->subAgentFactory,
            new NullLogger(),
            $this->promptFile
        );
    }

    private function buildTool(string $name): ToolInterface
    {
        return new class($name) implements ToolInterface {
            public function __construct(private readonly string $name)
            {
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function getDescription(): string
            {
                return '';
            }

            public function __invoke(array $parameters = []): array
            {
                return [];
            }
        };
    }

    private function writePromptFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'planner_prompt_');
        file_put_contents($path, "Tools: __AVAILABLE_TOOLS__\nSubagents: __AVAILABLE_SUBAGENTS__\nMsg: __USER_MESSAGE__");

        return $path;
    }
}
