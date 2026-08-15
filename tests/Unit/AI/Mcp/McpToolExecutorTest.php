<?php
// tests/Unit/AI/Mcp/McpToolExecutorTest.php

namespace App\Tests\Unit\AI\Mcp;

use App\AI\Mcp\McpToolExecutor;
use App\AI\Mcp\McpServerFactory;
use App\AI\Security\SecurityGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class McpToolExecutorTest extends TestCase
{
    private McpToolExecutor $executor;
    private McpServerFactory $factoryMock;
    private SecurityGuard $securityGuardMock;
    private LoggerInterface $loggerMock;

    protected function setUp(): void
    {
        $this->factoryMock = $this->createMock(McpServerFactory::class);
        $this->securityGuardMock = $this->createMock(SecurityGuard::class);
        $this->securityGuardMock->method('isToolAllowed')->willReturn(true);
        $this->loggerMock = $this->createMock(LoggerInterface::class);

        $this->executor = new McpToolExecutor(
            $this->factoryMock,
            $this->securityGuardMock,
            $this->loggerMock
        );
    }

    public function testExecuteSuccess(): void
    {
        $serverMock = $this->createMock(\App\AI\Mcp\McpServerInterface::class);
        $expectedResult = ['data' => 'test'];

        $this->factoryMock
            ->method('createByName')
            ->with('filesystem')
            ->willReturn($serverMock);

        $serverMock
            ->method('hasTool')
            ->with('read_file')
            ->willReturn(true);

        $serverMock
            ->method('isToolAllowed')
            ->with('read_file')
            ->willReturn(true);

        $this->securityGuardMock
            ->method('isToolAllowed')
            ->with('read_file')
            ->willReturn(true);

        $serverMock
            ->method('isResourceBlocked')
            ->willReturn(false);

        $serverMock
            ->method('executeTool')
            ->with('read_file', ['path' => '/test/file.txt'])
            ->willReturn($expectedResult);

        $result = $this->executor->execute('filesystem', 'read_file', ['path' => '/test/file.txt']);

        $this->assertEquals($expectedResult, $result);
    }

    public function testExecuteToolNotAvailable(): void
    {
        $serverMock = $this->createMock(\App\AI\Mcp\McpServerInterface::class);

        $this->factoryMock
            ->method('createByName')
            ->with('filesystem')
            ->willReturn($serverMock);

        $serverMock
            ->method('hasTool')
            ->with('nonexistent_tool')
            ->willReturn(false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Tool "nonexistent_tool" ist auf MCP-Server "filesystem" nicht verfügbar.');

        $this->executor->execute('filesystem', 'nonexistent_tool');
    }

    public function testExecuteToolNotAllowed(): void
    {
        $serverMock = $this->createMock(\App\AI\Mcp\McpServerInterface::class);

        $this->factoryMock
            ->method('createByName')
            ->with('filesystem')
            ->willReturn($serverMock);

        $serverMock
            ->method('hasTool')
            ->with('blocked_tool')
            ->willReturn(true);

        $serverMock
            ->method('isToolAllowed')
            ->with('blocked_tool')
            ->willReturn(false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Tool "blocked_tool" ist auf MCP-Server "filesystem" nicht erlaubt.');

        $this->executor->execute('filesystem', 'blocked_tool');
    }

    public function testExecuteToolBlockedBySecurityGuard(): void
    {
        $serverMock = $this->createMock(\App\AI\Mcp\McpServerInterface::class);

        $this->factoryMock
            ->method('createByName')
            ->with('filesystem')
            ->willReturn($serverMock);

        $serverMock
            ->method('hasTool')
            ->with('dangerous_tool')
            ->willReturn(true);

        $serverMock
            ->method('isToolAllowed')
            ->with('dangerous_tool')
            ->willReturn(true);

        $this->securityGuardMock
            ->method('isToolAllowed')
            ->with('dangerous_tool')
            ->willReturn(false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Tool "dangerous_tool" wurde durch SecurityGuard blockiert.');

        $this->executor->execute('filesystem', 'dangerous_tool');
    }

    public function testExecuteResourceBlocked(): void
    {
        $serverMock = $this->createMock(\App\AI\Mcp\McpServerInterface::class);

        $this->factoryMock
            ->method('createByName')
            ->with('filesystem')
            ->willReturn($serverMock);

        $serverMock
            ->method('hasTool')
            ->with('read_file')
            ->willReturn(true);

        $serverMock
            ->method('isToolAllowed')
            ->with('read_file')
            ->willReturn(true);

        $this->securityGuardMock
            ->method('isToolAllowed')
            ->with('read_file')
            ->willReturn(true);

        $serverMock
            ->method('isResourceBlocked')
            ->with('/etc/passwd')
            ->willReturn(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Ressource "/etc/passwd" ist auf diesem MCP-Server blockiert.');

        $this->executor->execute('filesystem', 'read_file', ['path' => '/etc/passwd']);
    }

    public function testGetAvailableServers(): void
    {
        $serverMock = $this->createMock(\App\AI\Mcp\McpServerInterface::class);

        $this->factoryMock
            ->method('getAvailableServers')
            ->willReturn(['filesystem' => $serverMock]);

        $result = $this->executor->getAvailableServers();

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertSame($serverMock, $result['filesystem']);
    }

    public function testGetServerTools(): void
    {
        $serverMock = $this->createMock(\App\AI\Mcp\McpServerInterface::class);
        $expectedTools = ['read_file', 'list_files'];

        $this->factoryMock
            ->method('createByName')
            ->with('filesystem')
            ->willReturn($serverMock);

        $serverMock
            ->method('getAvailableTools')
            ->willReturn($expectedTools);

        $result = $this->executor->getServerTools('filesystem');

        $this->assertEquals($expectedTools, $result);
    }

    public function testHasServerTool(): void
    {
        $serverMock = $this->createMock(\App\AI\Mcp\McpServerInterface::class);

        $this->factoryMock
            ->method('createByName')
            ->with('filesystem')
            ->willReturn($serverMock);

        $serverMock
            ->method('hasTool')
            ->with('read_file')
            ->willReturn(true);

        $result = $this->executor->hasServerTool('filesystem', 'read_file');

        $this->assertTrue($result);
    }

    public function testHasServerToolNotFound(): void
    {
        $this->factoryMock
            ->method('createByName')
            ->willThrowException(new \RuntimeException('Server not found'));

        $result = $this->executor->hasServerTool('nonexistent', 'read_file');

        $this->assertFalse($result);
    }

    public function testIsToolAllowed(): void
    {
        $serverMock = $this->createMock(\App\AI\Mcp\McpServerInterface::class);

        $this->factoryMock
            ->method('createByName')
            ->with('filesystem')
            ->willReturn($serverMock);

        $serverMock
            ->method('isToolAllowed')
            ->with('read_file')
            ->willReturn(true);

        $result = $this->executor->isToolAllowed('filesystem', 'read_file');

        $this->assertTrue($result);
    }

    public function testIsToolAllowedNotFound(): void
    {
        $this->factoryMock
            ->method('createByName')
            ->willThrowException(new \RuntimeException('Server not found'));

        $result = $this->executor->isToolAllowed('nonexistent', 'read_file');

        $this->assertFalse($result);
    }

    public function testGetActiveServerDefinitions(): void
    {
        $definition1 = $this->createMock(\App\Entity\McpServerDefinition::class);
        $definition2 = $this->createMock(\App\Entity\McpServerDefinition::class);

        $this->factoryMock
            ->method('getActiveServerDefinitions')
            ->willReturn([$definition1, $definition2]);

        $result = $this->executor->getActiveServerDefinitions();

        $this->assertCount(2, $result);
    }

    public function testExecuteToolAutoServer(): void
    {
        $serverMock = $this->createMock(\App\AI\Mcp\McpServerInterface::class);
        $expectedResult = ['data' => 'test'];

        $this->factoryMock
            ->method('getAvailableServers')
            ->willReturn([
                'filesystem' => $serverMock,
            ]);

        // execute() ruft createByName auf — muss denselben Mock liefern.
        $this->factoryMock
            ->method('createByName')
            ->with('filesystem')
            ->willReturn($serverMock);

        $serverMock
            ->method('hasTool')
            ->with('read_file')
            ->willReturn(true);

        $serverMock
            ->method('isToolAllowed')
            ->with('read_file')
            ->willReturn(true);

        $serverMock
            ->method('executeTool')
            ->with('read_file', ['path' => '/test/file.txt'])
            ->willReturn($expectedResult);

        $result = $this->executor->executeTool('read_file', ['path' => '/test/file.txt']);

        $this->assertEquals($expectedResult, $result);
    }

    public function testExecuteToolNoSuitableServer(): void
    {
        $serverMock = $this->createMock(\App\AI\Mcp\McpServerInterface::class);

        $this->factoryMock
            ->method('getAvailableServers')
            ->willReturn([
                'filesystem' => $serverMock,
            ]);

        $serverMock
            ->method('hasTool')
            ->with('nonexistent_tool')
            ->willReturn(false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Kein MCP-Server gefunden, der Tool "nonexistent_tool" unterstützt.');

        $this->executor->executeTool('nonexistent_tool');
    }
}
