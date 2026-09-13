<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Agent;

use App\AI\Agent\OrchestratorDialogService;
use App\AI\Agent\SubAgentFactory;
use App\AI\Response\FaultTolerantValidator;
use App\AI\Response\JsonResponseEnforcer;
use App\AI\Response\ResponseNormalizer;
use App\AI\Skills\ToolDefinitionGenerator;
use App\Repository\ToolDefinitionRepository;
use App\Tests\Stub\StubAgent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Unit-Tests fuer die Agent-Orchestrierung inklusive LLM-Abruf (Blueprint §4.A).
 *
 * Verifiziert, dass der Orchestrator den nativen Symfony AI Agent aufruft
 * (LLM-Abruf) und die Antwortstruktur (tool_call / subagent_delegation /
 * dialog) korrekt dispatcht. Der StubAgent ersetzt den echten Mistral-Aufruf
 * deterministisch, sodass der LLM-Abruf-Pfad (AgentInterface::call) vollstaendig
 * durchlaufen wird, ohne echte API-Kosten zu verursachen.
 *
 * LLM-Aufrufe werden minimiert: genau 1 Abruf pro ask()-Aufruf.
 */
final class OrchestratorAgentLlmTest extends TestCase
{
    private \Symfony\AI\Agent\AgentInterface $agent;
    private ToolDefinitionGenerator&MockObject $toolGenerator;
    private SubAgentFactory&MockObject $subAgentFactory;
    private JsonResponseEnforcer $jsonResponseEnforcer;
    private ResponseNormalizer $responseNormalizer;
    private \Symfony\AI\Platform\Result\DeferredResult $intentClassification;

    protected function setUp(): void
    {
        $this->jsonResponseEnforcer = new JsonResponseEnforcer(
            $this->createMock(PlatformInterface::class),
            new NullLogger(),
            new ResponseNormalizer(new NullLogger())
        );
        $this->responseNormalizer = new ResponseNormalizer(new NullLogger());
        // Default-Intent ist CONVERSATION; Tests koennen es ueberschreiben.
        $this->intentClassification = \App\Tests\Stub\StubDeferredResult::withText('CONVERSATION');
    }

    public function testToolCallResponseDispatchedAfterSingleLlmCall(): void
    {
        $llmResponse = json_encode([
            'type' => 'tool_call',
            'tool_name' => 'weather',
            'parameters' => ['city' => 'Berlin'],
        ], JSON_THROW_ON_ERROR);

        $this->agent = new StubAgent($llmResponse);
        $this->toolGenerator = $this->createMock(ToolDefinitionGenerator::class);
        $this->subAgentFactory = $this->createMock(SubAgentFactory::class);

        $orchestrator = $this->buildOrchestrator();
        $result = $orchestrator->ask('Wie ist das Wetter in Berlin?', 'user-123');

        // Genau 1 LLM-Abruf (minimiert).
        self::assertSame(1, $this->agent->getCallCount());
        self::assertIsString($result);
        self::assertStringContainsString('weather', $result);
    }

    public function testSubAgentDelegationExecutesSubAgent(): void
    {
        $llmResponse = json_encode([
            'type' => 'subagent_delegation',
            'subagent' => 'data_analyst',
            'task' => 'Analysiere die Verkaufsdaten',
            'reason' => 'Datenanalyse erforderlich',
        ], JSON_THROW_ON_ERROR);

        $this->agent = new StubAgent($llmResponse);

        // Der Sub-Agent wird vom Orchestrator aufgerufen -> eigener Stub.
        $subAgent = new StubAgent('Datenanalyse abgeschlossen: 42 Verkaeufe');
        $this->subAgentFactory = $this->createMock(SubAgentFactory::class);
        $this->subAgentFactory->method('createDataAnalysisAgent')->willReturn($subAgent);

        $this->toolGenerator = $this->createMock(ToolDefinitionGenerator::class);

        $orchestrator = $this->buildOrchestrator();
        $result = $orchestrator->ask('Analysiere die Daten', 'user-456');

        // 1 Abruf fuer Orchestrator + 1 Abruf fuer Sub-Agent = 2 LLM-Aufrufe.
        self::assertSame(1, $this->agent->getCallCount());
        self::assertSame(1, $subAgent->getCallCount());
        self::assertStringContainsString('Datenanalyse', $result);
    }

    public function testDialogResponseReturnsContentDirectly(): void
    {
        $llmResponse = json_encode([
            'type' => 'dialog',
            'content' => 'Hallo, ich kann dir helfen.',
            'message' => 'Hallo, ich kann dir helfen.',
        ], JSON_THROW_ON_ERROR);

        $this->agent = new StubAgent($llmResponse);
        $this->toolGenerator = $this->createMock(ToolDefinitionGenerator::class);
        $this->subAgentFactory = $this->createMock(SubAgentFactory::class);

        $orchestrator = $this->buildOrchestrator();
        $result = $orchestrator->ask('Hallo', 'user-789');

        self::assertSame(1, $this->agent->getCallCount());
        self::assertIsString($result);
        self::assertNotEmpty($result);
    }

    public function testToolNotFoundTriggersToolGenerationOnlyForTask(): void
    {
        // Eine konkrete ausfuehrbare Aufgabe ohne passendes Tool (TASK) loest
        // die Tool-Generierung aus (Blueprint 95: Evolution-Flow).
        $llmResponse = json_encode([
            'type' => 'no_tool_found',
            'message' => 'Kein passendes Tool vorhanden',
        ], JSON_THROW_ON_ERROR);

        $this->agent = new StubAgent($llmResponse);
        $this->intentClassification = \App\Tests\Stub\StubDeferredResult::withText('TASK');

        // ToolDefinitionGenerator wird nur bei TASK aufgerufen.
        $this->toolGenerator = $this->createMock(ToolDefinitionGenerator::class);
        $this->toolGenerator->expects(self::atLeastOnce())->method('generateToolDefinition');
        $this->subAgentFactory = $this->createMock(SubAgentFactory::class);

        $orchestrator = $this->buildOrchestrator();
        $result = $orchestrator->ask('Rufe die API example.com auf und gib mir die Daten', 'user-gen');

        self::assertSame(1, $this->agent->getCallCount());
        self::assertIsString($result);
    }

    public function testToolNotFoundConversationDoesNotGenerateTool(): void
    {
        // Konversation/Begrueßung -> Dialog-Antwort, KEINE Tool-Generierung.
        $llmResponse = json_encode([
            'type' => 'no_tool_found',
            'message' => 'Kein passendes Tool vorhanden',
        ], JSON_THROW_ON_ERROR);

        $this->agent = new StubAgent($llmResponse);
        $this->intentClassification = \App\Tests\Stub\StubDeferredResult::withText('CONVERSATION');

        $this->toolGenerator = $this->createMock(ToolDefinitionGenerator::class);
        $this->toolGenerator->expects(self::never())->method('generateToolDefinition');
        $this->subAgentFactory = $this->createMock(SubAgentFactory::class);

        $orchestrator = $this->buildOrchestrator();
        $result = $orchestrator->ask('moin wer bist du', 'user-con');

        self::assertIsString($result);
    }

    public function testToolNotFoundUnclearReturnsClarifyingQuestion(): void
    {
        // Mehrdeutige Anfrage -> Rueckfrage statt Tool-Generierung.
        $llmResponse = json_encode([
            'type' => 'no_tool_found',
            'message' => 'Kein passendes Tool vorhanden',
        ], JSON_THROW_ON_ERROR);

        $this->agent = new StubAgent($llmResponse);
        $this->intentClassification = \App\Tests\Stub\StubDeferredResult::withText('UNCLEAR');

        $this->toolGenerator = $this->createMock(ToolDefinitionGenerator::class);
        $this->toolGenerator->expects(self::never())->method('generateToolDefinition');
        $this->subAgentFactory = $this->createMock(SubAgentFactory::class);

        $orchestrator = $this->buildOrchestrator();
        $result = $orchestrator->ask('irgendwas', 'user-unclear');

        self::assertIsString($result);
        self::assertStringContainsString('genauer', $result);
    }

    public function testLlmExceptionReturnsReadableMessageWithoutToolGeneration(): void
    {
        // Agent wirft Exception -> Orchestrator antwortet menschenahnlich mit
        // einer lesbaren Nachricht und erfindet KEIN Tool mehr.
        $failingAgent = $this->buildFailingAgent();

        $this->agent = $failingAgent;
        $this->toolGenerator = $this->createMock(ToolDefinitionGenerator::class);
        $this->toolGenerator->expects(self::never())->method('generateToolDefinition');
        $this->subAgentFactory = $this->createMock(SubAgentFactory::class);

        $orchestrator = $this->buildOrchestrator();
        $result = $orchestrator->ask('Fehler-Test', 'user-err');

        self::assertIsString($result);
        self::assertStringContainsString('nicht verarbeiten', $result);
    }

    private function buildOrchestrator(): OrchestratorDialogService
    {
        // Platform-Mock liefert die Intent-Klassifizierung als Text.
        $platform = $this->createMock(PlatformInterface::class);
        $platform->method('invoke')->willReturn($this->intentClassification);

        return new OrchestratorDialogService(
            $this->agent,
            $this->toolGenerator,
            $this->subAgentFactory,
            $this->createMock(EventDispatcherInterface::class),
            new NullLogger(),
            $platform,
            $this->createMock(UrlGeneratorInterface::class),
            $this->jsonResponseEnforcer,
            $this->createMock(FaultTolerantValidator::class),
            $this->responseNormalizer,
            $this->createMock(ToolDefinitionRepository::class),
            new \App\AI\Agent\LlmRetryExecutor(new NullLogger(), maxRetries: 1, initialDelayMs: 1),
        );
    }

private function buildFailingAgent(): \Symfony\AI\Agent\AgentInterface
    {
        return new class implements \Symfony\AI\Agent\AgentInterface {
            public function call(string|\Symfony\AI\Platform\Message\MessageBag|\Symfony\AI\Platform\Message\UserMessage $input, array $options = []): \Symfony\AI\Platform\Result\ResultInterface
            {
                throw new \RuntimeException('Mistral API timeout');
            }

            public function getName(): string
            {
                return 'failing_orchestrator';
            }
        };
    }
}
