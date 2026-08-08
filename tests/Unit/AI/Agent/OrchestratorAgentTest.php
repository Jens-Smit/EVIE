<?php

namespace App\Tests\Unit\AI\Agent;

use App\AI\Agent\OrchestratorDialogService;
use App\AI\Skills\ToolDefinitionGenerator;
use App\Event\PendingToolApprovalEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class OrchestratorAgentTest extends TestCase
{
    private OrchestratorDialogService $orchestrator;
    private AgentInterface $agent;
    private ToolDefinitionGenerator $toolGenerator;
    private EventDispatcherInterface $dispatcher;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->agent = $this->createMock(AgentInterface::class);
        $this->toolGenerator = $this->createMock(ToolDefinitionGenerator::class);
        $this->dispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->orchestrator = new OrchestratorDialogService(
            $this->agent,
            $this->toolGenerator,
            $this->dispatcher,
            $this->logger,
        );
    }

    public function testHandlePromptWithAvailableTools(): void
    {
        $userMessage = 'Analysiere diese Daten';
        $userIdentifier = 'user123';
        $expectedResponse = 'Daten wurden analysiert.';

        $resultResponse = new TextResult($expectedResponse);

        $this->agent->expects($this->once())
            ->method('call')
            ->with(
                $this->callback(function (MessageBag $messages) use ($userIdentifier) {
                    $systemMessage = $messages->getMessages()[0] ?? null;
                    $userMessage = $messages->getMessages()[1] ?? null;

                    return $systemMessage !== null
                        && $userMessage !== null
                        && str_contains((string) $systemMessage->getContent(), $userIdentifier);
                })
            )
            ->willReturn($resultResponse);

        $result = $this->orchestrator->ask($userMessage, $userIdentifier);

        $this->assertEquals($expectedResponse, $result);
    }

    public function testHandlePromptWithMissingTools(): void
    {
        $userMessage = 'Analysiere diese Excel-Datei';
        $userIdentifier = 'user123';
        $expectedResponse = 'Excel-Tool wird benötigt.';

        $resultResponse = new TextResult($expectedResponse);

        $this->agent->expects($this->once())
            ->method('call')
            ->with(
                $this->callback(function (MessageBag $messages) use ($userIdentifier) {
                    $systemMessage = $messages->getMessages()[0] ?? null;
                    $userMessage = $messages->getMessages()[1] ?? null;

                    return $systemMessage !== null
                        && $userMessage !== null
                        && str_contains((string) $systemMessage->getContent(), $userIdentifier);
                })
            )
            ->willReturn($resultResponse);

        $result = $this->orchestrator->ask($userMessage, $userIdentifier);

        $this->assertEquals($expectedResponse, $result);
    }

    public function testAnalyzePromptForExcel(): void
    {
        $userMessage = 'Analysiere diese Excel-Datei';
        $userIdentifier = 'user123';
        $expectedResponse = 'ExcelParserTool wird benötigt.';

        $resultResponse = new TextResult($expectedResponse);

        $this->agent->expects($this->once())
            ->method('call')
            ->with(
                $this->callback(function (MessageBag $messages) use ($userIdentifier) {
                    $systemMessage = $messages->getMessages()[0] ?? null;
                    $userMessage = $messages->getMessages()[1] ?? null;

                    return $systemMessage !== null
                        && $userMessage !== null
                        && str_contains((string) $systemMessage->getContent(), $userIdentifier);
                })
            )
            ->willReturn($resultResponse);

        $result = $this->orchestrator->ask($userMessage, $userIdentifier);

        $this->assertStringContainsString('ExcelParserTool', $result);
    }

    public function testAnalyzePromptForAnalysis(): void
    {
        $userMessage = 'Ich möchte eine Datenanalyse durchführen';
        $userIdentifier = 'user123';
        $expectedResponse = 'DataAnalyzerTool wird benötigt.';

        $resultResponse = new TextResult($expectedResponse);

        $this->agent->expects($this->once())
            ->method('call')
            ->with(
                $this->callback(function (MessageBag $messages) use ($userIdentifier) {
                    $systemMessage = $messages->getMessages()[0] ?? null;
                    $userMessage = $messages->getMessages()[1] ?? null;

                    return $systemMessage !== null
                        && $userMessage !== null
                        && str_contains((string) $systemMessage->getContent(), $userIdentifier);
                })
            )
            ->willReturn($resultResponse);

        $result = $this->orchestrator->ask($userMessage, $userIdentifier);

        $this->assertStringContainsString('DataAnalyzerTool', $result);
    }
}