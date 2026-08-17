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
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use App\AI\Agent\LlmRetryExecutor;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Unit-Tests für OrchestratorDialogService (Blueprint §4.A).
 *
 * Verifiziert, dass der Orchestrator den nativen Agent aufruft und eine
 * Antwort zurückgibt. Die detaillierte Tool-Calling- und Evolution-Logik
 * ist in EvolutionFlowIntegrationTest abgedeckt.
 */
class OrchestratorAgentTest extends TestCase
{
    private OrchestratorDialogService $orchestrator;
    private AgentInterface $agent;

    protected function setUp(): void
    {
        $this->agent = $this->createMock(AgentInterface::class);
        $this->orchestrator = new OrchestratorDialogService(
            $this->agent,
            $this->createMock(ToolDefinitionGenerator::class),
            $this->createMock(SubAgentFactory::class),
            $this->createMock(EventDispatcherInterface::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(PlatformInterface::class),
            $this->createMock(UrlGeneratorInterface::class),
            $this->createMock(JsonResponseEnforcer::class),
            $this->createMock(FaultTolerantValidator::class),
            $this->createMock(ResponseNormalizer::class),
            $this->createMock(ToolDefinitionRepository::class),
            new \App\AI\Agent\LlmRetryExecutor(
                $this->createMock(LoggerInterface::class),
                maxRetries: 2,
                initialDelayMs: 1,
            ),
        );
    }

    public function testAskInvokesAgentAndReturnsString(): void
    {
        $this->agent
            ->expects(self::once())
            ->method('call')
            ->willReturnCallback(function (MessageBag $messages): TextResult {
                // Verifiziere, dass die User-Nachricht übergeben wurde.
                $msgs = $messages->getMessages();
                self::assertNotEmpty($msgs);

                return new TextResult('Antwort des Agenten');
            });

        $result = $this->orchestrator->ask('Analysiere diese Daten', 'user123');

        self::assertIsString($result);
        self::assertNotEmpty($result);
    }

    public function testAskForwardsDifferentPrompts(): void
    {
        $this->agent
            ->expects(self::once())
            ->method('call')
            ->willReturn(new TextResult('Excel verarbeitet'));

        $result = $this->orchestrator->ask('Analysiere diese Excel-Datei', 'user123');

        self::assertIsString($result);
    }

    public function testAskHandlesDataAnalysisRequest(): void
    {
        $this->agent
            ->expects(self::once())
            ->method('call')
            ->willReturn(new TextResult('Analyse abgeschlossen'));

        $result = $this->orchestrator->ask('Analysiere die Daten', 'user456');

        self::assertIsString($result);
        self::assertNotEmpty($result);
    }

    public function testAskWorksForExcelPrompt(): void
    {
        $this->agent
            ->expects(self::once())
            ->method('call')
            ->willReturn(new TextResult('Excel-Ergebnis'));

        $result = $this->orchestrator->ask('Verarbeite Excel', 'user789');

        self::assertIsString($result);
    }
}
