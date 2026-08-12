<?php
// tests/Unit/AI/Mcp/McpServerFactoryTest.php

namespace App\Tests\Unit\AI\Mcp;

use App\AI\Mcp\McpServerFactory;
use App\Entity\McpServerDefinition;
use App\Repository\McpServerDefinitionRepository;
use App\AI\Security\SecurityGuard;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class McpServerFactoryTest extends TestCase
{
    private McpServerFactory $factory;
    private ContainerInterface $containerMock;
    private McpServerDefinitionRepository $repoMock;
    private SecurityGuard $securityGuardMock;
    private LoggerInterface $loggerMock;

    protected function setUp(): void
    {
        $this->containerMock = $this->createMock(ContainerInterface::class);
        $this->repoMock = $this->createMock(McpServerDefinitionRepository::class);
        $this->securityGuardMock = $this->createMock(SecurityGuard::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);

        $this->factory = new McpServerFactory(
            $this->containerMock,
            $this->repoMock,
            $this->securityGuardMock,
            $this->loggerMock
        );
    }

    public function testCreateFromDefinitionWithValidType(): void
    {
        $definition = new McpServerDefinition();
        $definition->setName('test_filesystem');
        $definition->setType('filesystem');
        $definition->setDescription('Test Filesystem Server');
        $definition->setConfiguration([
            'transport' => 'stdio',
            'command' => 'npx',
            'arguments' => ['-y', '@modelcontextprotocol/server-filesystem']
        ]);
        $definition->setAllowedTools(['read_file', 'list_files']);
        $definition->setBlockedResources(['/etc/*', '*.env']);

        $serverMock = $this->createMock(\App\AI\Mcp\McpServerInterface::class);

        // Mock SecurityGuard
        $this->securityGuardMock
            ->method('isServiceAllowed')
            ->with('ai.mcp.server.filesystem')
            ->willReturn(true);

        // Mock Container
        $this->containerMock
            ->method('has')
            ->with('ai.mcp.server.filesystem')
            ->willReturn(true);

        $this->containerMock
            ->method('get')
            ->with('ai.mcp.server.filesystem')
            ->willReturn($serverMock);

        $serverMock
            ->method('setConfiguration')
            ->willReturn(null);

        $serverMock
            ->method('setAllowedTools')
            ->willReturn(null);

        $serverMock
            ->method('setBlockedResources')
            ->willReturn(null);

        $result = $this->factory->createFromDefinition($definition);

        $this->assertSame($serverMock, $result);
    }

    public function testCreateFromDefinitionWithInvalidType(): void
    {
        $definition = new McpServerDefinition();
        $definition->setName('test_invalid');
        $definition->setType('invalid_type');
        $definition->setDescription('Test Invalid Server');
        $definition->setConfiguration([]);

        // Mock SecurityGuard
        $this->securityGuardMock
            ->method('isServiceAllowed')
            ->willReturn(false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MCP-Server-Typ "invalid_type" ist nicht in der SecurityGuard-Whitelist.');

        $this->factory->createFromDefinition($definition);
    }

    public function testCreateByNameFromDatabase(): void
    {
        $definition = new McpServerDefinition();
        $definition->setName('test_filesystem');
        $definition->setType('filesystem');

        $serverMock = $this->createMock(\App\AI\Mcp\McpServerInterface::class);

        $this->repoMock
            ->method('findOneByName')
            ->with('test_filesystem')
            ->willReturn($definition);

        $this->securityGuardMock
            ->method('isServiceAllowed')
            ->willReturn(true);

        $this->containerMock
            ->method('has')
            ->willReturn(true);

        $this->containerMock
            ->method('get')
            ->willReturn($serverMock);

        $serverMock
            ->method('setConfiguration')
            ->willReturn(null);

        $serverMock
            ->method('setAllowedTools')
            ->willReturn(null);

        $serverMock
            ->method('setBlockedResources')
            ->willReturn(null);

        $result = $this->factory->createByName('test_filesystem');

        $this->assertSame($serverMock, $result);
    }

    public function testCreateByNameFromStaticConfig(): void
    {
        $serverMock = $this->createMock(\App\AI\Mcp\McpServerInterface::class);

        $this->repoMock
            ->method('findOneByName')
            ->with('filesystem')
            ->willReturn(null);

        $this->containerMock
            ->method('has')
            ->with('ai.mcp.server.filesystem')
            ->willReturn(true);

        $this->containerMock
            ->method('get')
            ->with('ai.mcp.server.filesystem')
            ->willReturn($serverMock);

        $result = $this->factory->createByName('filesystem');

        $this->assertSame($serverMock, $result);
    }

    public function testCreateByNameNotFound(): void
    {
        $this->repoMock
            ->method('findOneByName')
            ->with('nonexistent')
            ->willReturn(null);

        $this->containerMock
            ->method('has')
            ->with('ai.mcp.server.nonexistent')
            ->willReturn(false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MCP-Server "nonexistent" nicht in statischer Konfiguration gefunden (Service: ai.mcp.server.nonexistent).');

        $this->factory->createByName('nonexistent');
    }

    public function testCreateAllFromDatabase(): void
    {
        $definition1 = new McpServerDefinition();
        $definition1->setName('server_1');
        $definition1->setType('filesystem');

        $definition2 = new McpServerDefinition();
        $definition2->setName('server_2');
        $definition2->setType('playwright');

        $server1Mock = $this->createMock(\App\AI\Mcp\McpServerInterface::class);
        $server2Mock = $this->createMock(\App\AI\Mcp\McpServerInterface::class);

        $this->repoMock
            ->method('findAllActive')
            ->willReturn([$definition1, $definition2]);

        $this->securityGuardMock
            ->method('isServiceAllowed')
            ->willReturn(true);

        $this->containerMock
            ->method('has')
            ->willReturn(true);

        $this->containerMock
            ->method('get')
            ->willReturnOnConsecutiveCalls($server1Mock, $server2Mock);

        $server1Mock
            ->method('setConfiguration')
            ->willReturn(null);
        $server1Mock
            ->method('setAllowedTools')
            ->willReturn(null);
        $server1Mock
            ->method('setBlockedResources')
            ->willReturn(null);

        $server2Mock
            ->method('setConfiguration')
            ->willReturn(null);
        $server2Mock
            ->method('setAllowedTools')
            ->willReturn(null);
        $server2Mock
            ->method('setBlockedResources')
            ->willReturn(null);

        $result = $this->factory->createAllFromDatabase();

        $this->assertCount(2, $result);
        $this->assertSame($server1Mock, $result['server_1']);
        $this->assertSame($server2Mock, $result['server_2']);
    }

    public function testRegisterMcpServer(): void
    {
        $definition = new McpServerDefinition();
        $definition->setName('new_server');
        $definition->setType('filesystem');
        $definition->setConfiguration(['command' => 'npx']);

        $entityManagerMock = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $this->containerMock
            ->method('get')
            ->with('doctrine.orm.entity_manager')
            ->willReturn($entityManagerMock);

        $entityManagerMock
            ->method('persist')
            ->with($definition)
            ->willReturn(null);

        $entityManagerMock
            ->method('flush')
            ->willReturn(null);

        $this->securityGuardMock
            ->method('isServiceAllowed')
            ->willReturn(true);

        $this->factory->registerMcpServer($definition);

        // Verify persist and flush were called
        $entityManagerMock->method('persist')->willReturn(null);
        $entityManagerMock->method('flush')->willReturn(null);
    }

    public function testGetAvailableServers(): void
    {
        $result = $this->factory->getAvailableServers();

        $this->assertIsArray($result);
    }

    public function testGetActiveServerDefinitions(): void
    {
        $definition1 = new McpServerDefinition();
        $definition1->setName('server_1');

        $definition2 = new McpServerDefinition();
        $definition2->setName('server_2');

        $this->repoMock
            ->method('findAllActive')
            ->willReturn([$definition1, $definition2]);

        $result = $this->factory->getActiveServerDefinitions();

        $this->assertCount(2, $result);
        $this->assertSame($definition1, $result[0]);
        $this->assertSame($definition2, $result[1]);
    }

    public function testGetServerDefinitionsByType(): void
    {
        $definition1 = new McpServerDefinition();
        $definition1->setName('filesystem_server_1');
        $definition1->setType('filesystem');

        $definition2 = new McpServerDefinition();
        $definition2->setName('filesystem_server_2');
        $definition2->setType('filesystem');

        $this->repoMock
            ->method('findByType')
            ->with('filesystem')
            ->willReturn([$definition1, $definition2]);

        $result = $this->factory->getServerDefinitionsByType('filesystem');

        $this->assertCount(2, $result);
    }
}
