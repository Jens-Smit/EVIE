<?php

namespace App\Tests\Unit\AI\Skills\Tool;

use App\AI\Skills\Tool\DynamicToolExecutor;
use App\Entity\ToolDefinition;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Unit-Tests für DynamicToolExecutor
 * 
 * Testet:
 * - Tool-Ausführung für verschiedene Typen
 * - SecurityGuard-Integration
 * - Fehlerbehandlung
 */
final class DynamicToolExecutorTest extends TestCase
{
    private DynamicToolExecutor $executor;
    private ContainerInterface $container;

    protected function setUp(): void
    {
        $this->container = $this->createMock(ContainerInterface::class);
        $securityGuard = $this->createMock(\App\AI\Security\SecurityGuard::class);
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);

        $this->executor = new DynamicToolExecutor($this->container, $securityGuard, $logger);
    }

    // ========================================================================
    // Helper-Methoden
    // ========================================================================

    private function createToolDefinition(
        string $name = 'test_tool',
        array $schema = []
    ): ToolDefinition {
        $toolDefinition = new ToolDefinition();
        $toolDefinition->setName($name);
        $toolDefinition->setDescription('Test Tool Description');
        $toolDefinition->setStatus('approved');
        $toolDefinition->setSchema($schema);
        return $toolDefinition;
    }

    // ========================================================================
    // Tests für execute() mit Service-Tools
    // ========================================================================

    public function testExecuteWithServiceTool(): void
    {
        $toolDefinition = $this->createToolDefinition(
            'service_tool',
            ['service' => 'App\AI\Skills\Tool\GenericApiExecutor']
        );

        $service = $this->createMock(\App\AI\Skills\Tool\GenericApiExecutor::class);
        $service->method('__invoke')->willReturn('Service Ergebnis');

        $this->container->method('has')->with('App\AI\Skills\Tool\GenericApiExecutor')->willReturn(true);
        $this->container->method('get')->with('App\AI\Skills\Tool\GenericApiExecutor')->willReturn($service);

        $result = $this->executor->execute($toolDefinition, ['input' => 'test']);
        $this->assertEquals('Service Ergebnis', $result);
    }

    public function testExecuteWithServiceToolUsingExecuteMethod(): void
    {
        $toolDefinition = $this->createToolDefinition(
            'service_tool',
            ['service' => 'App\AI\Skills\Tool\CustomService']
        );

        $service = $this->createMock(\stdClass::class);
        $service->method('execute')->willReturn('Execute Ergebnis');

        $this->container->method('has')->willReturn(true);
        $this->container->method('get')->willReturn($service);

        $result = $this->executor->execute($toolDefinition, []);
        $this->assertEquals('Execute Ergebnis', $result);
    }

    public function testExecuteWithServiceToolUsingRunMethod(): void
    {
        $toolDefinition = $this->createToolDefinition(
            'service_tool',
            ['service' => 'App\AI\Skills\Tool\CustomService']
        );

        $service = $this->createMock(\stdClass::class);
        $service->method('run')->willReturn('Run Ergebnis');

        $this->container->method('has')->willReturn(true);
        $this->container->method('get')->willReturn($service);

        $result = $this->executor->execute($toolDefinition, []);
        $this->assertEquals('Run Ergebnis', $result);
    }

    public function testExecuteWithNonExistentServiceThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('nicht gefunden');

        $toolDefinition = $this->createToolDefinition(
            'nonexistent_service_tool',
            ['service' => 'App\AI\Skills\Tool\NonExistentService']
        );

        $this->container->method('has')->willReturn(false);

        $this->executor->execute($toolDefinition, []);
    }

    // ========================================================================
    // Tests für execute() mit API-Tools
    // ========================================================================

    public function testExecuteWithApiTool(): void
    {
        $toolDefinition = $this->createToolDefinition(
            'api_tool',
            [
                'type' => 'api',
                'api_config' => [
                    'endpoint' => 'https://api.example.com/data',
                    'method' => 'GET',
                ],
            ]
        );

        $apiExecutor = $this->createMock(\App\AI\Skills\Tool\GenericApiExecutor::class);
        $apiExecutor->method('__invoke')->willReturn(['data' => 'test']);

        $this->container->method('has')->with('App\AI\Skills\Tool\GenericApiExecutor')->willReturn(true);
        $this->container->method('get')->with('App\AI\Skills\Tool\GenericApiExecutor')->willReturn($apiExecutor);

        $result = $this->executor->execute($toolDefinition, []);
        $this->assertEquals(['data' => 'test'], $result);
    }

    // ========================================================================
    // Tests für execute() mit Datenbank-Tools
    // ========================================================================

    public function testExecuteWithDatabaseTool(): void
    {
        $toolDefinition = $this->createToolDefinition(
            'db_tool',
            [
                'type' => 'database',
                'query_config' => [
                    'query' => 'SELECT * FROM users',
                    'connection' => 'default',
                ],
            ]
        );

        $dbExecutor = $this->createMock(\App\AI\Skills\Tool\DatabaseQueryExecutor::class);
        $dbExecutor->method('__invoke')->willReturn([['id' => 1, 'name' => 'Test']]);

        $this->container->method('has')->with('App\AI\Skills\Tool\DatabaseQueryExecutor')->willReturn(true);
        $this->container->method('get')->with('App\AI\Skills\Tool\DatabaseQueryExecutor')->willReturn($dbExecutor);

        $result = $this->executor->execute($toolDefinition, []);
        $this->assertEquals([['id' => 1, 'name' => 'Test']], $result);
    }

    // ========================================================================
    // Tests für execute() mit Dateisystem-Tools
    // ========================================================================

    public function testExecuteWithFilesystemTool(): void
    {
        $toolDefinition = $this->createToolDefinition(
            'fs_tool',
            [
                'type' => 'filesystem',
                'path' => '/tmp/test.txt',
            ]
        );

        $fsExecutor = $this->createMock(\App\AI\Skills\Tool\FileSystemReadExecutor::class);
        $fsExecutor->method('__invoke')->willReturn('File Content');

        $this->container->method('has')->with('App\AI\Skills\Tool\FileSystemReadExecutor')->willReturn(true);
        $this->container->method('get')->with('App\AI\Skills\Tool\FileSystemReadExecutor')->willReturn($fsExecutor);

        $result = $this->executor->execute($toolDefinition, []);
        $this->assertEquals('File Content', $result);
    }

    // ========================================================================
    // Tests für execute() mit HTTP-Tools
    // ========================================================================

    public function testExecuteWithHttpTool(): void
    {
        $toolDefinition = $this->createToolDefinition(
            'http_tool',
            [
                'type' => 'http',
                'http_config' => [
                    'url' => 'https://api.example.com/data',
                    'method' => 'POST',
                    'headers' => ['Content-Type' => 'application/json'],
                ],
            ]
        );

        $httpExecutor = $this->createMock(\App\AI\Skills\Tool\HttpClientExecutor::class);
        $httpExecutor->method('__invoke')->willReturn(['response' => 'success']);

        $this->container->method('has')->with('App\AI\Skills\Tool\HttpClientExecutor')->willReturn(true);
        $this->container->method('get')->with('App\AI\Skills\Tool\HttpClientExecutor')->willReturn($httpExecutor);

        $result = $this->executor->execute($toolDefinition, []);
        $this->assertEquals(['response' => 'success'], $result);
    }

    // ========================================================================
    // Tests für execute() mit generischen Tools
    // ========================================================================

    public function testExecuteWithGenericTool(): void
    {
        $toolDefinition = $this->createToolDefinition(
            'generic_tool',
            []
        );

        $result = $this->executor->execute($toolDefinition, ['input' => 'test']);

        $this->assertIsArray($result);
        $this->assertEquals('success', $result['status']);
        $this->assertEquals('generic_tool', $result['tool']);
        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('timestamp', $result);
    }

    // ========================================================================
    // Tests für SecurityGuard-Integration
    // ========================================================================

    public function testExecuteWithBlockedServiceThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Ausführung des Tools');

        $toolDefinition = $this->createToolDefinition(
            'blocked_tool',
            ['service' => 'App\AI\Skills\Tool\DangerousExecutor']
        );

        $securityGuard = $this->createMock(\App\AI\Security\SecurityGuard::class);
        $securityGuard->method('assertToolAllowed')->willThrowException(new \RuntimeException('not allowed'));

        $executor = new DynamicToolExecutor($this->container, $securityGuard, $this->createMock(\Psr\Log\LoggerInterface::class));
        $executor->execute($toolDefinition, []);
    }

    public function testExecuteWithBlockedResourceThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Ausführung des Tools');

        $toolDefinition = $this->createToolDefinition(
            'blocked_resource_tool',
            ['resource' => '/etc/passwd']
        );

        $securityGuard = $this->createMock(\App\AI\Security\SecurityGuard::class);
        $securityGuard->method('assertToolAllowed')->willThrowException(new \RuntimeException('not allowed'));

        $executor = new DynamicToolExecutor($this->container, $securityGuard, $this->createMock(\Psr\Log\LoggerInterface::class));
        $executor->execute($toolDefinition, []);
    }

    // ========================================================================
    // Tests für Fehlerbehandlung
    // ========================================================================

    public function testExecuteWithServiceWithoutInvokeMethodThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('hat keine ausführbare Methode');

        $toolDefinition = $this->createToolDefinition(
            'invalid_service_tool',
            ['service' => 'App\AI\Skills\Tool\InvalidService']
        );

        $service = new \stdClass(); // Keine __invoke, execute oder run Methode

        $this->container->method('has')->willReturn(true);
        $this->container->method('get')->willReturn($service);

        $this->executor->execute($toolDefinition, []);
    }

    // ========================================================================
    // Tests für Script-Tool (sollte blockiert sein)
    // ========================================================================

    public function testExecuteWithScriptToolThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Script-Ausführung ist aus Sicherheitsgründen deaktiviert');

        $toolDefinition = $this->createToolDefinition(
            'script_tool',
            ['type' => 'script']
        );

        $this->executor->execute($toolDefinition, []);
    }

    // ========================================================================
    // Edge Cases
    // ========================================================================

    public function testExecuteWithEmptyArguments(): void
    {
        $toolDefinition = $this->createToolDefinition('generic_tool', []);
        $result = $this->executor->execute($toolDefinition, []);

        $this->assertIsArray($result);
        $this->assertEquals('success', $result['status']);
    }

    public function testExecuteWithNullArguments(): void
    {
        $toolDefinition = $this->createToolDefinition('generic_tool', []);
        $result = $this->executor->execute($toolDefinition, null);

        $this->assertIsArray($result);
    }
}
