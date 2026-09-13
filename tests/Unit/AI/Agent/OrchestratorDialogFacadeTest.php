<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Agent;

use App\AI\Agent\LlmRetryExecutor;
use App\AI\Agent\OrchestratorDialogService;
use App\AI\Agent\SubAgentFactory;
use App\AI\Pipeline\Execution\PipelineResult;
use App\AI\Pipeline\PipelineInterface;
use App\AI\Response\FaultTolerantValidator;
use App\AI\Response\JsonResponseEnforcer;
use App\AI\Response\ResponseNormalizer;
use App\AI\Skills\ToolDefinitionGenerator;
use App\Repository\ToolDefinitionRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Unit-Test fuer die OrchestratorDialogService-Fassade: sofern eine
 * PipelineInterface-Implementierung injiziert wurde, delegiert ask() an
 * diese und liefert PipelineResult::getContent(). Das ist die Stufe-7-
 * Verdrahtung der 5-Phasen-Pipeline (Goal -> Intent -> Plan -> Capability
 * -> Execution).
 *
 * @see docs/architecture/orchestrator-pipeline.md Phase 7
 */
final class OrchestratorDialogFacadeTest extends TestCase
{
    public function testAskDelegatesToPipelineWhenInjected(): void
    {
        $pipeline = $this->createMock(PipelineInterface::class);
        $pipeline->expects(self::once())
            ->method('run')
            ->with('moin', 'user-1')
            ->willReturn(new PipelineResult(PipelineResult::TYPE_DIALOG, 'Hallo von der Pipeline'));

        $service = $this->buildService($pipeline);
        $result = $service->ask('moin', 'user-1');

        self::assertSame('Hallo von der Pipeline', $result);
    }

    public function testAskUsesLegacyPathWhenNoPipelineInjected(): void
    {
        // Ohne injizierte Pipeline faellt ask() auf den bestehenden LLM-Pfad
        // zurueck (Rueckwaertskompatibilitaet fuer bestehende Tests).
        $service = $this->buildService(null);
        $result = $service->ask('Fehler-Test', 'user-err');

        self::assertIsString($result);
    }

    private function buildService(?PipelineInterface $pipeline): OrchestratorDialogService
    {
        $agent = new class implements AgentInterface {
            public function call(string|\Symfony\AI\Platform\Message\MessageBag|\Symfony\AI\Platform\Message\UserMessage $input, array $options = []): \Symfony\AI\Platform\Result\ResultInterface
            {
                throw new \RuntimeException('legacy path');
            }

            public function getName(): string
            {
                return 'orchestrator';
            }
        };
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('https://tools.example/pending');

        return new OrchestratorDialogService(
            $agent,
            $this->createMock(ToolDefinitionGenerator::class),
            $this->createMock(SubAgentFactory::class),
            $this->createMock(EventDispatcherInterface::class),
            new NullLogger(),
            $this->createMock(PlatformInterface::class),
            $urlGenerator,
            new JsonResponseEnforcer($this->createMock(PlatformInterface::class), new NullLogger(), new ResponseNormalizer(new NullLogger())),
            $this->createMock(FaultTolerantValidator::class),
            new ResponseNormalizer(new NullLogger()),
            $this->createMock(ToolDefinitionRepository::class),
            new LlmRetryExecutor(new NullLogger(), maxRetries: 1, initialDelayMs: 1),
            $pipeline
        );
    }
}
