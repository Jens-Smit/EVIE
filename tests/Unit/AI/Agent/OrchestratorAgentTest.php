<?php

namespace App\Tests\Unit\AI\Agent;

use App\AI\Agent\OrchestratorDialogService;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;

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

        $messageBag = new MessageBag(Message::ofUser($userMessage));
        $resultMessage = $this->createMock(Message::class);
        $resultMessage->method('getContent')->willReturn($expectedResponse);

        $this->agent->expects($this->once())
            ->method('call')
            ->with(
                $this->equalTo($messageBag),
                $this->equalTo(['user_identifier' => $userIdentifier])
            )
            ->willReturn($resultMessage);

        $result = $this->orchestrator->ask($userMessage, $userIdentifier);

        $this->assertEquals($expectedResponse, $result);
    }

    public function testHandlePromptWithMissingTools(): void
    {
        $userMessage = 'Analysiere diese Excel-Datei';
        $userIdentifier = 'user123';
        $expectedResponse = 'Excel-Tool wird benötigt.';

        $messageBag = new MessageBag(Message::ofUser($userMessage));
        $resultMessage = $this->createMock(Message::class);
        $resultMessage->method('getContent')->willReturn($expectedResponse);

        $this->agent->expects($this->once())
            ->method('call')
            ->with(
                $this->equalTo($messageBag),
                $this->equalTo(['user_identifier' => $userIdentifier])
            )
            ->willReturn($resultMessage);

        $result = $this->orchestrator->ask($userMessage, $userIdentifier);

        $this->assertEquals($expectedResponse, $result);
    }

    public function testAnalyzePromptForExcel(): void
    {
        $userMessage = 'Analysiere diese Excel-Datei';
        $userIdentifier = 'user123';
        $expectedResponse = 'ExcelParserTool wird benötigt.';

        $messageBag = new MessageBag(Message::ofUser($userMessage));
        $resultMessage = $this->createMock(Message::class);
        $resultMessage->method('getContent')->willReturn($expectedResponse);

        $this->agent->expects($this->once())
            ->method('call')
            ->with(
                $this->equalTo($messageBag),
                $this->equalTo(['user_identifier' => $userIdentifier])
            )
            ->willReturn($resultMessage);

        $result = $this->orchestrator->ask($userMessage, $userIdentifier);

        $this->assertStringContainsString('ExcelParserTool', $result);
    }

    public function testAnalyzePromptForAnalysis(): void
    {
        $userMessage = 'Ich möchte eine Datenanalyse durchführen';
        $userIdentifier = 'user123';
        $expectedResponse = 'DataAnalyzerTool wird benötigt.';

        $messageBag = new MessageBag(Message::ofUser($userMessage));
        $resultMessage = $this->createMock(Message::class);
        $resultMessage->method('getContent')->willReturn($expectedResponse);

        $this->agent->expects($this->once())
            ->method('call')
            ->with(
                $this->equalTo($messageBag),
                $this->equalTo(['user_identifier' => $userIdentifier])
            )
            ->willReturn($resultMessage);

        $result = $this->orchestrator->ask($userMessage, $userIdentifier);

        $this->assertStringContainsString('DataAnalyzerTool', $result);
    }
}