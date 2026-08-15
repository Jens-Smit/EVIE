<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Skills;

use App\AI\Skills\DynamicToolbox;
use App\Entity\ToolDefinition;
use App\Repository\ToolDefinitionRepository;
use App\Security\UserContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;

/**
 * Unit-Tests für die native DynamicToolbox (Blueprint §4.B).
 *
 * Verifiziert, dass getTools() die statischen Tools der inneren Toolbox mit
 * den dynamischen ToolDefinition-Entities (Status "approved") als native
 * Symfony\AI\Platform\Tool\Tool-Objekte merged.
 */
final class DynamicToolboxTest extends TestCase
{
    public function testGetToolsMergesStaticAndDynamicTools(): void
    {
        $staticTool = new Tool(
            new ExecutionReference('App\\AI\\Skills\\Tool\\WeatherTool'),
            'weather',
            'Liefert Wetterdaten',
            ['type' => 'object', 'properties' => ['city' => ['type' => 'string']], 'required' => ['city']],
        );

        $dynamicDefinition = (new ToolDefinition())
            ->setName('excel_parser')
            ->setDescription('Parst Excel-Dateien')
            ->setSchema(['type' => 'object', 'properties' => []])
            ->setStatus('approved')
            ->setExecutorType('filesystem');

        $repo = $this->createMock(ToolDefinitionRepository::class);
        $repo->method('findAllApproved')->willReturn([$dynamicDefinition]);

        $inner = $this->createMock(ToolboxInterface::class);
        $inner->method('getTools')->willReturn([$staticTool]);

        $toolbox = new DynamicToolbox($inner, $repo, $this->createUserContext());
        $tools = $toolbox->getTools();

        self::assertCount(2, $tools);
        $names = array_map(static fn (Tool $t): string => $t->getName(), $tools);
        self::assertContains('weather', $names);
        self::assertContains('excel_parser', $names);
    }

    public function testDynamicToolBuiltAsNativeToolWithExecutorReference(): void
    {
        $dynamicDefinition = (new ToolDefinition())
            ->setName('api_call')
            ->setDescription('REST-API-Aufruf')
            ->setSchema(['type' => 'object'])
            ->setStatus('approved')
            ->setExecutorType('api');

        $repo = $this->createMock(ToolDefinitionRepository::class);
        $repo->method('findAllApproved')->willReturn([$dynamicDefinition]);

        $inner = $this->createMock(ToolboxInterface::class);
        $inner->method('getTools')->willReturn([]);

        $toolbox = new DynamicToolbox($inner, $repo, $this->createUserContext());
        $tools = $toolbox->getTools();

        self::assertCount(1, $tools);
        $tool = $tools[0];
        self::assertInstanceOf(Tool::class, $tool);
        self::assertSame('api_call', $tool->getName());
        self::assertSame('REST-API-Aufruf', $tool->getDescription());
        self::assertSame('App\\AI\\Skills\\Executor\\GenericApiExecutor', $tool->getReference()->getClass());
    }

    public function testGetToolsWithoutApprovedDefinitionsReturnsOnlyStaticTools(): void
    {
        $staticTool = new Tool(new ExecutionReference('App\\AI\\Skills\\Tool\\FileReadTool'), 'file_read', 'Liest Dateien');

        $repo = $this->createMock(ToolDefinitionRepository::class);
        $repo->method('findAllApproved')->willReturn([]);

        $inner = $this->createMock(ToolboxInterface::class);
        $inner->method('getTools')->willReturn([$staticTool]);

        $toolbox = new DynamicToolbox($inner, $repo, $this->createUserContext());
        $tools = $toolbox->getTools();

        self::assertCount(1, $tools);
        self::assertSame('file_read', $tools[0]->getName());
    }

    public function testExecuteDelegatesToInnerToolbox(): void
    {
        $toolCall = new ToolCall('call-1', 'weather', ['city' => 'Berlin']);
        $expectedResult = new ToolResult($toolCall, 'Sonnig, 24°C');

        $inner = $this->createMock(ToolboxInterface::class);
        $inner->expects(self::once())
            ->method('execute')
            ->with(self::identicalTo($toolCall))
            ->willReturn($expectedResult);

        $repo = $this->createMock(ToolDefinitionRepository::class);

        $toolbox = new DynamicToolbox($inner, $repo, $this->createUserContext());
        $result = $toolbox->execute($toolCall);

        self::assertSame($expectedResult, $result);
    }

    public function testGetToolsSurvivesRepositoryFailure(): void
    {
        $repo = $this->createMock(ToolDefinitionRepository::class);
        $repo->method('findAllApproved')->willThrowException(new \RuntimeException('DB offline'));

        $inner = $this->createMock(ToolboxInterface::class);
        $inner->method('getTools')->willReturn([]);

        $toolbox = new DynamicToolbox($inner, $repo, $this->createUserContext());
        $tools = $toolbox->getTools();

        self::assertSame([], $tools);
    }

    public function testApprovedToolAppearsAfterRuntimeApproval(): void
    {
        $repo = $this->createMock(ToolDefinitionRepository::class);

        $inner = $this->createMock(ToolboxInterface::class);
        $inner->method('getTools')->willReturn([]);

        $toolbox = new DynamicToolbox($inner, $repo, $this->createUserContext());

        // Zunächst kein approved Tool.
        $repo->method('findAllApproved')->willReturn([]);
        self::assertCount(0, $toolbox->getTools());

        // Nach Freigabe liefert das Repository das Tool — getTools() reflektiert
        // dies beim nächsten Agent-Call (kein Cache, keine CompilerPass-Registrierung).
        $approved = (new ToolDefinition())
            ->setName('new_tool')
            ->setDescription('Neu genehmigt')
            ->setStatus('approved')
            ->setExecutorType('generic');

        $repo = $this->createMock(ToolDefinitionRepository::class);
        $repo->method('findAllApproved')->willReturn([$approved]);
        $toolbox = new DynamicToolbox($inner, $repo, $this->createUserContext());

        $tools = $toolbox->getTools();
        self::assertCount(1, $tools);
        self::assertSame('new_tool', $tools[0]->getName());
    }

    private function createUserContext(): UserContext
    {
        $requestStack = new RequestStack();
        $requestStack->push(new Request());

        return new UserContext($requestStack);
    }

}
