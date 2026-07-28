<?php

namespace App\Tests\Unit\AI\Agent;

use App\AI\Agent\OrchestratorDialogService;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\TextResult;

class OrchestratorAgentTest extends TestCase
{
    private OrchestratorDialogService $orchestrator;
    private AgentInterface $agent;

    protected function setUp(): void
    {
        $this->agent = $this->createMock(AgentInterface::class);
        $this->orchestrator = new OrchestratorDialogService($this->agent);
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
                $this->isInstanceOf(MessageBag::class),
                $this->equalTo(['user_identifier' => $userIdentifier])
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
                $this->isInstanceOf(MessageBag::class),
                $this->equalTo(['user_identifier' => $userIdentifier])
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
                $this->isInstanceOf(MessageBag::class),
                $this->equalTo(['user_identifier' => $userIdentifier])
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
                $this->isInstanceOf(MessageBag::class),
                $this->equalTo(['user_identifier' => $userIdentifier])
            )
            ->willReturn($resultResponse);

        $result = $this->orchestrator->ask($userMessage, $userIdentifier);

        $this->assertStringContainsString('DataAnalyzerTool', $result);
    }
}