<?php

namespace App\Tests\Unit\AI\Agent;

use App\AI\Agent\OrchestratorDialogService;
use App\AI\Agent\SubAgentFactory;
use App\AI\Response\JsonResponseEnforcer;
use App\AI\Response\FaultTolerantValidator;
use App\AI\Response\ResponseNormalizer;
use App\Repository\ToolDefinitionRepository;
use App\AI\Skills\ToolDefinitionGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class OrchestratorAgentTest extends TestCase
{
    private OrchestratorDialogService $orchestrator;
    private AgentInterface $agent;
    private ToolDefinitionGenerator $toolGenerator;
    private EventDispatcherInterface $dispatcher;
    private LoggerInterface $logger;
    private PlatformInterface $platform;
    private UrlGeneratorInterface $urlGenerator;
    private SubAgentFactory $subAgentFactory;
    private JsonResponseEnforcer $jsonResponseEnforcer;
    private FaultTolerantValidator $faultTolerantValidator;
    private ResponseNormalizer $responseNormalizer;
    private ToolDefinitionRepository $toolDefinitionRepo;

    protected function setUp(): void
    {
        $this->agent = $this->createMock(AgentInterface::class);
        $this->toolGenerator = $this->createMock(ToolDefinitionGenerator::class);
        $this->dispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->platform = $this->createMock(PlatformInterface::class);
        $this->urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $this->subAgentFactory = $this->createMock(SubAgentFactory::class);
        $this->jsonResponseEnforcer = $this->createMock(JsonResponseEnforcer::class);
        $this->faultTolerantValidator = $this->createMock(FaultTolerantValidator::class);
        $this->responseNormalizer = $this->createMock(ResponseNormalizer::class);
        $this->toolDefinitionRepo = $this->createMock(ToolDefinitionRepository::class);

        $this->orchestrator = new OrchestratorDialogService(
            $this->agent,
            $this->toolGenerator,
            $this->subAgentFactory,
            $this->dispatcher,
            $this->logger,
            $this->platform,
            $this->urlGenerator,
            $this->jsonResponseEnforcer,
            $this->faultTolerantValidator,
            $this->responseNormalizer,
            $this->toolDefinitionRepo,
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
                $this->callback(function (MessageBag $messages) use ($userMessage) {
                    $messagesArr = $messages->getMessages();
                    return count($messagesArr) === 1
                        && $messagesArr[0]->asText() === $userMessage;
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
                $this->callback(function (MessageBag $messages) use ($userMessage) {
                    $messagesArr = $messages->getMessages();
                    return count($messagesArr) === 1
                        && $messagesArr[0]->asText() === $userMessage;
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
                $this->callback(function (MessageBag $messages) use ($userMessage) {
                    $messagesArr = $messages->getMessages();
                    return count($messagesArr) === 1
                        && $messagesArr[0]->asText() === $userMessage;
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
                $this->callback(function (MessageBag $messages) use ($userMessage) {
                    $messagesArr = $messages->getMessages();
                    return count($messagesArr) === 1
                        && $messagesArr[0]->asText() === $userMessage;
                })
            )
            ->willReturn($resultResponse);

        $result = $this->orchestrator->ask($userMessage, $userIdentifier);

        $this->assertStringContainsString('DataAnalyzerTool', $result);
    }
}