<?php
// tests/Unit/Controller/HTMX/HTMXControllerTest.php

namespace App\Tests\Unit\Controller\HTMX;

use App\AI\Agent\SubAgentDispatcher;
use App\AI\Mcp\McpToolExecutor;
use App\AI\Skills\Tool\DynamicToolExecutor;
use App\AI\Streaming\StreamingSessionManager;
use App\Controller\HTMX\HTMXController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class HTMXControllerTest extends TestCase
{
    private HTMXController $controller;
    private DynamicToolExecutor $toolExecutorMock;
    private SubAgentDispatcher $subAgentDispatcherMock;
    private McpToolExecutor $mcpToolExecutorMock;
    private StreamingSessionManager $sessionManagerMock;

    protected function setUp(): void
    {
        $this->toolExecutorMock = $this->createMock(DynamicToolExecutor::class);
        $this->subAgentDispatcherMock = $this->createMock(SubAgentDispatcher::class);
        $this->mcpToolExecutorMock = $this->createMock(McpToolExecutor::class);
        $this->sessionManagerMock = $this->createMock(StreamingSessionManager::class);

        $this->controller = new HTMXController(
            $this->toolExecutorMock,
            $this->subAgentDispatcherMock,
            $this->mcpToolExecutorMock,
            $this->sessionManagerMock
        );
    }

    public function testExecuteToolSuccess(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('request')->willReturnSelf();
        $request->method('get')->willReturnMap([
            ['tool_name', null, 'test_tool'],
            ['arguments', null, '["arg1":"value1"]'],
        ]);

        $this->toolExecutorMock
            ->method('execute')
            ->with('test_tool', ['arg1' => 'value1'])
            ->willReturn(['result' => 'success']);

        $response = $this->controller->executeTool($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testExecuteToolMissingToolName(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('request')->willReturnSelf();
        $request->method('get')->willReturn(null);

        $response = $this->controller->executeTool($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testExecuteToolException(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('request')->willReturnSelf();
        $request->method('get')->willReturnMap([
            ['tool_name', null, 'test_tool'],
            ['arguments', null, '[]'],
        ]);

        $this->toolExecutorMock
            ->method('execute')
            ->willThrowException(new \Exception('Test error'));

        $response = $this->controller->executeTool($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testToolForm(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('query')->willReturnSelf();
        $request->method('get')->willReturn(null);

        $this->toolExecutorMock
            ->method('getAvailableTools')
            ->willReturn(['tool1', 'tool2']);

        $response = $this->controller->toolForm($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testDelegateToSubAgentSuccess(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('request')->willReturnSelf();
        $request->method('get')->willReturnMap([
            ['task', null, 'Test task'],
            ['sub_agent_name', null, 'website_researcher'],
        ]);

        $this->subAgentDispatcherMock
            ->method('delegateTo')
            ->with('website_researcher', 'Test task')
            ->willReturn([
                'sub_agent' => 'website_researcher',
                'result' => ['data' => 'test'],
                'status' => 'success',
            ]);

        $response = $this->controller->delegateToSubAgent($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testDelegateToSubAgentAutoSelect(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('request')->willReturnSelf();
        $request->method('get')->willReturnMap([
            ['task', null, 'Test task @data_analyst'],
            ['sub_agent_name', null, ''],
        ]);

        $this->subAgentDispatcherMock
            ->method('delegate')
            ->with('Test task @data_analyst')
            ->willReturn([
                'sub_agent' => 'data_analyst',
                'result' => ['data' => 'test'],
                'status' => 'success',
            ]);

        $response = $this->controller->delegateToSubAgent($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testDelegateToSubAgentMissingTask(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('request')->willReturnSelf();
        $request->method('get')->willReturn(null);

        $response = $this->controller->delegateToSubAgent($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testExecuteMcpToolSuccess(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('request')->willReturnSelf();
        $request->method('get')->willReturnMap([
            ['server_name', null, 'filesystem'],
            ['tool_name', null, 'read_file'],
            ['arguments', null, '["path":"test.txt"]'],
        ]);

        $this->mcpToolExecutorMock
            ->method('execute')
            ->with('filesystem', 'read_file', ['path' => 'test.txt'])
            ->willReturn(['content' => 'test']);

        $response = $this->controller->executeMcpTool($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testExecuteMcpToolMissingServerOrTool(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('request')->willReturnSelf();
        $request->method('get')->willReturn(null);

        $response = $this->controller->executeMcpTool($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testStartStreamingSessionSuccess(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('request')->willReturnSelf();
        $request->method('get')->willReturnMap([
            ['tool_name', null, 'test_tool'],
            ['arguments', null, '["arg1":"value1"]'],
        ]);

        $sessionMock = $this->createMock(\App\Entity\StreamingSession::class);
        $sessionMock->method('getSessionId')->willReturn('session_123');

        $this->sessionManagerMock
            ->method('createSession')
            ->with('test_tool', ['arg1' => 'value1'], 'user_123')
            ->willReturn($sessionMock);

        $response = $this->controller->startStreamingSession($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testStartStreamingSessionMissingToolName(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('request')->willReturnSelf();
        $request->method('get')->willReturn(null);

        $response = $this->controller->startStreamingSession($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testStreamingSessionStatusSuccess(): void
    {
        $sessionMock = $this->createMock(\App\Entity\StreamingSession::class);

        $this->sessionManagerMock
            ->method('getSession')
            ->with('session_123')
            ->willReturn($sessionMock);

        $response = $this->controller->streamingSessionStatus('session_123');

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testStreamingSessionStatusNotFound(): void
    {
        $this->sessionManagerMock
            ->method('getSession')
            ->with('nonexistent')
            ->willReturn(null);

        $response = $this->controller->streamingSessionStatus('nonexistent');

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(404, $response->getStatusCode());
    }

    public function testDashboard(): void
    {
        $this->toolExecutorMock
            ->method('getAvailableTools')
            ->willReturn(['tool1', 'tool2']);

        $this->subAgentDispatcherMock
            ->method('getAvailableSubAgents')
            ->willReturn(['agent1', 'agent2']);

        $this->mcpToolExecutorMock
            ->method('getAvailableServers')
            ->willReturn(['server1', 'server2']);

        $this->sessionManagerMock
            ->method('getActiveSessionsByUser')
            ->willReturn([]);

        $response = $this->controller->dashboard();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testSuccessMessage(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('query')->willReturnSelf();
        $request->method('get')->willReturn('Test success message');

        $response = $this->controller->successMessage($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testErrorMessage(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('query')->willReturnSelf();
        $request->method('get')->willReturn('Test error message');

        $response = $this->controller->errorMessage($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testLoadingIndicator(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('query')->willReturnSelf();
        $request->method('get')->willReturn('Loading...');

        $response = $this->controller->loadingIndicator($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
    }
}
