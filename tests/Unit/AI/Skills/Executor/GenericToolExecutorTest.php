<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Skills\Executor;

use App\AI\Security\SecurityGuard;
use App\AI\Skills\Executor\GenericToolExecutor;
use App\AI\Skills\Tool\ToolInterface;
use App\AI\Skills\Tool\ToolRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class GenericToolExecutorTest extends TestCase
{
    private SecurityGuard&MockObject $guard;

    private function makeRegistry(ToolInterface ...$tools): ToolRegistry
    {
        return new ToolRegistry($tools);
    }

    private function makeTool(string $name, array $invokeReturn = [], ?\Closure $invokeExpect = null): ToolInterface&MockObject
    {
        $tool = $this->createMock(ToolInterface::class);
        $tool->method('getName')->willReturn($name);
        $mock = $tool->method('__invoke');
        if ($invokeExpect !== null) {
            $mock->willReturnCallback($invokeExpect);
        } else {
            $mock->willReturn($invokeReturn);
        }

        return $tool;
    }

    protected function setUp(): void
    {
        $this->guard = $this->createMock(SecurityGuard::class);
    }

    public function testGetNameAndDescription(): void
    {
        $executor = new GenericToolExecutor($this->makeRegistry(), $this->guard);
        self::assertSame('generic_tool_executor', $executor->getName());
        self::assertNotEmpty($executor->getDescription());
    }

    public function testInvokeThrowsWhenToolNameMissing(): void
    {
        $executor = new GenericToolExecutor($this->makeRegistry(), $this->guard);
        $this->expectException(\InvalidArgumentException::class);
        $executor->__invoke(['param' => 'value']);
    }

    public function testInvokeAcceptsNameAlias(): void
    {
        $this->guard->method('containsShellMetacharacters')->willReturn(false);
        $tool = $this->makeTool('my_tool', ['status' => 'ok']);
        $executor = new GenericToolExecutor($this->makeRegistry($tool), $this->guard);

        $result = $executor->__invoke(['name' => 'my_tool']);

        self::assertSame(['status' => 'ok'], $result);
    }

    public function testInvokeDispatchesToRegisteredTool(): void
    {
        $this->guard->method('containsShellMetacharacters')->willReturn(false);
        $tool = $this->makeTool('web_search', ['status' => 'success']);
        $executor = new GenericToolExecutor($this->makeRegistry($tool), $this->guard);

        $result = $executor->__invoke(['tool_name' => 'web_search', 'q' => 'evie']);

        self::assertSame(['status' => 'success'], $result);
    }

    public function testInvokeThrowsOnShellMetacharacters(): void
    {
        $this->guard->method('containsShellMetacharacters')->willReturn(true);
        $executor = new GenericToolExecutor($this->makeRegistry(), $this->guard);

        $this->expectException(\RuntimeException::class);
        $executor->__invoke(['tool_name' => 'shell', 'cmd' => 'rm -rf /']);
    }

    public function testInvokeChecksAllStringParametersForShellMetacharacters(): void
    {
        $this->guard->method('containsShellMetacharacters')
            ->willReturnCallback(static fn (string $v): bool => $v === 'evil; rm -rf');
        $executor = new GenericToolExecutor($this->makeRegistry(), $this->guard);

        $this->expectException(\RuntimeException::class);
        $executor->__invoke(['tool_name' => 't', 'safe' => 'ok', 'bad' => 'evil; rm -rf']);
    }

    public function testExecuteDelegatesToInvoke(): void
    {
        $this->guard->method('containsShellMetacharacters')->willReturn(false);
        $tool = $this->makeTool('db_query', ['done' => true]);
        $executor = new GenericToolExecutor($this->makeRegistry($tool), $this->guard);

        $result = $executor->execute('db_query', ['limit' => 5]);

        self::assertSame(['done' => true], $result);
    }

    public function testInvokeThrowsWhenToolNotFound(): void
    {
        $this->guard->method('containsShellMetacharacters')->willReturn(false);
        $executor = new GenericToolExecutor($this->makeRegistry(), $this->guard);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/nicht gefunden/');
        $executor->__invoke(['tool_name' => 'unknown']);
    }
}
