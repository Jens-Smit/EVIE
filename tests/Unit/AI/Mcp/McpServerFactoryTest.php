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
            ->expects(self::once())
            ->method('persist')
            ->with($definition);

        $entityManagerMock
            ->expects(self::once())
            ->method('flush');

        $this->securityGuardMock
            ->method('isServiceAllowed')
            ->willReturn(true);

        $this->factory->registerMcpServer($definition);

        self::assertSame('new_server', $definition->getName());
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

    public function testCreateFromDefinitionPlaywrightValidatesCommand(): void
    {
        $definition = new McpServerDefinition();
        $definition->setName('test_playwright');
        $definition->setType('playwright');
        $definition->setDescription('Test');
        $definition->setConfiguration(['transport' => 'stdio', 'command' => 'npx']);

        $serverMock = $this->createMock(\App\AI\Mcp\McpServerInterface::class);
        $this->securityGuardMock->method('isServiceAllowed')->willReturn(true);
        $this->containerMock->method('has')->willReturn(true);
        $this->containerMock->method('get')->willReturn($serverMock);

        $result = $this->factory->createFromDefinition($definition);
        $this->assertSame($serverMock, $result);
    }

    public function testCreateFromDefinitionPlaywrightBlockedCommandThrows(): void
    {
        $definition = new McpServerDefinition();
        $definition->setName('bad_playwright');
        $definition->setType('playwright');
        $definition->setDescription('Test');
        $definition->setConfiguration(['command' => 'evil-cmd']);

        $this->securityGuardMock->method('isServiceAllowed')->willReturnMap([
            ['ai.mcp.server.playwright', true],
            ['evil-cmd', false],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Command');

        $this->factory->createFromDefinition($definition);
    }

    public function testCreateFromDefinitionGithubBlockedUrlThrows(): void
    {
        $definition = new McpServerDefinition();
        $definition->setName('bad_github');
        $definition->setType('github');
        $definition->setDescription('Test');
        $definition->setConfiguration(['url' => 'http://169.254.169.254/']);

        $this->securityGuardMock->method('isServiceAllowed')->willReturn(true);
        $this->securityGuardMock->method('isResourceBlocked')->willReturn(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ist in der SecurityGuard-Blocklist');

        $this->factory->createFromDefinition($definition);
    }

    public function testCreateFromDefinitionGithubValidUrlPasses(): void
    {
        $definition = new McpServerDefinition();
        $definition->setName('ok_github');
        $definition->setType('github');
        $definition->setDescription('Test');
        $definition->setConfiguration(['url' => 'https://api.github.com']);

        $serverMock = $this->createMock(\App\AI\Mcp\McpServerInterface::class);
        $this->securityGuardMock->method('isServiceAllowed')->willReturn(true);
        $this->securityGuardMock->method('isResourceBlocked')->willReturn(false);
        $this->containerMock->method('has')->willReturn(true);
        $this->containerMock->method('get')->willReturn($serverMock);

        $result = $this->factory->createFromDefinition($definition);
        $this->assertSame($serverMock, $result);
    }

    public function testCreateFromDefinitionCustomBlockedClassThrows(): void
    {
        $definition = new McpServerDefinition();
        $definition->setName('bad_custom');
        $definition->setType('custom');
        $definition->setDescription('Test');
        $definition->setConfiguration(['class' => 'Evil\\Class']);

        $this->securityGuardMock->method('isServiceAllowed')->willReturnMap([
            ['ai.mcp.server.custom', true],
            ['Evil\\Class', false],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ist nicht in der SecurityGuard-Whitelist');

        $this->factory->createFromDefinition($definition);
    }

    public function testCreateFromDefinitionCustomValidClassPasses(): void
    {
        $definition = new McpServerDefinition();
        $definition->setName('ok_custom');
        $definition->setType('custom');
        $definition->setDescription('Test');
        $definition->setConfiguration(['class' => 'App\\Safe\\Class']);

        $serverMock = $this->createMock(\App\AI\Mcp\McpServerInterface::class);
        $this->securityGuardMock->method('isServiceAllowed')->willReturn(true);
        $this->containerMock->method('has')->willReturn(true);
        $this->containerMock->method('get')->willReturn($serverMock);

        $result = $this->factory->createFromDefinition($definition);
        $this->assertSame($serverMock, $result);
    }

    public function testCreateFromDefinitionFilesystemBlockedArgumentThrows(): void
    {
        $definition = new McpServerDefinition();
        $definition->setName('bad_fs');
        $definition->setType('filesystem');
        $definition->setDescription('Test');
        $definition->setConfiguration([
            'transport' => 'stdio',
            'command' => 'npx',
            'arguments' => ['/etc/passwd'],
        ]);

        $this->securityGuardMock->method('isServiceAllowed')->willReturn(true);
        $this->securityGuardMock->method('isResourceBlocked')->willReturn(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ist in der SecurityGuard-Blocklist');

        $this->factory->createFromDefinition($definition);
    }

    public function testCreateFromDefinitionFilesystemShellMetacharArgumentThrows(): void
    {
        $definition = new McpServerDefinition();
        $definition->setName('bad_fs_shell');
        $definition->setType('filesystem');
        $definition->setDescription('Test');
        $definition->setConfiguration([
            'transport' => 'stdio',
            'command' => 'npx',
            'arguments' => ['valid', 'cmd; rm -rf /'],
        ]);

        $this->securityGuardMock->method('isServiceAllowed')->willReturn(true);
        $this->securityGuardMock->method('isResourceBlocked')->willReturn(false);
        $this->securityGuardMock->method('containsShellMetacharacters')->willReturn(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Shell-Metazeichen');

        $this->factory->createFromDefinition($definition);
    }

    public function testCreateFromDefinitionThrowsWhenServiceNotImplementsInterface(): void
    {
        $definition = new McpServerDefinition();
        $definition->setName('bad_iface');
        $definition->setType('filesystem');
        $definition->setDescription('Test');
        $definition->setConfiguration(['transport' => 'stdio', 'command' => 'npx']);

        $this->securityGuardMock->method('isServiceAllowed')->willReturn(true);
        $this->containerMock->method('has')->willReturn(true);
        $this->containerMock->method('get')->willReturn(new \stdClass());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('implementiert McpServerInterface nicht');

        $this->factory->createFromDefinition($definition);
    }

    public function testCreateAllFromDatabaseContinuesOnError(): void
    {
        $goodDefinition = new McpServerDefinition();
        $goodDefinition->setName('ok');
        $goodDefinition->setType('filesystem');
        $goodDefinition->setDescription('Test');
        $goodDefinition->setConfiguration(['transport' => 'stdio', 'command' => 'npx']);

        $badDefinition = new McpServerDefinition();
        $badDefinition->setName('bad');
        $badDefinition->setType('invalid_type');
        $badDefinition->setDescription('Test');
        $badDefinition->setConfiguration([]);

        $this->repoMock->method('findAllActive')->willReturn([$badDefinition, $goodDefinition]);
        $this->securityGuardMock->method('isServiceAllowed')->willReturnMap([
            ['ai.mcp.server.invalid_type', false],
            ['ai.mcp.server.filesystem', true],
            ['npx', true],
        ]);
        $serverMock = $this->createMock(\App\AI\Mcp\McpServerInterface::class);
        $this->containerMock->method('has')->willReturn(true);
        $this->containerMock->method('get')->willReturn($serverMock);
        $this->loggerMock->method('error');

        $servers = $this->factory->createAllFromDatabase();
        $this->assertArrayHasKey('ok', $servers);
        $this->assertArrayNotHasKey('bad', $servers);
    }
}
