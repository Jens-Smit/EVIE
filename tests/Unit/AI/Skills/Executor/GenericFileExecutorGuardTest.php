<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Skills\Executor;

use App\AI\Security\SecurityGuard;
use App\AI\Skills\Executor\GenericFileExecutor;
use App\AI\Skills\Tool\DynamicTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * C-1: GenericFileExecutor muss jeden Dateizugriff ueber SecurityGuard::isPathSafe()
 * leiten, damit Directory-Traversal, Symlink-Escapes und sensitive Pfade auch
 * ausserhalb des nativen Agent-Loops (DynamicToolExecutor-Pfad) blockiert werden.
 */
final class GenericFileExecutorGuardTest extends TestCase
{
    private function createTool(string $action = 'read'): DynamicTool
    {
        return new DynamicTool('file_tool', 'desc', [], 'filesystem', ['action' => $action]);
    }

    public function testReadIsBlockedWhenPathIsUnsafe(): void
    {
        $guard = $this->createMock(SecurityGuard::class);
        $guard->expects(self::once())
            ->method('isPathSafe')
            ->with('/etc/passwd')
            ->willReturn(false);

        $executor = new GenericFileExecutor($guard);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SecurityGuard blockiert');
        $executor->execute($this->createTool('read'), ['path' => '/etc/passwd']);
    }

    public function testWriteIsBlockedWhenPathIsUnsafe(): void
    {
        $guard = $this->createMock(SecurityGuard::class);
        $guard->expects(self::once())
            ->method('isPathSafe')
            ->with('/root/.ssh/authorized_keys')
            ->willReturn(false);

        $executor = new GenericFileExecutor($guard);

        $this->expectException(\RuntimeException::class);
        $executor->execute($this->createTool('write'), [
            'path' => '/root/.ssh/authorized_keys',
            'content' => 'evil',
        ]);
    }

    public function testDeleteIsBlockedWhenPathIsUnsafe(): void
    {
        $guard = $this->createMock(SecurityGuard::class);
        $guard->expects(self::once())
            ->method('isPathSafe')
            ->with('/proc/1')
            ->willReturn(false);

        $executor = new GenericFileExecutor($guard);

        $this->expectException(\RuntimeException::class);
        $executor->execute($this->createTool('delete'), ['path' => '/proc/1']);
    }

    public function testDirectoryTraversalIsBlockedBeforeFileAccess(): void
    {
        $guard = new SecurityGuard(new NullLogger());

        $executor = new GenericFileExecutor($guard);

        // ../ ist laut isPathSafe immer unsicher; der Executor darf die Datei
        // nicht einmal mit file_exists() pruefen (kein Bestandteil der
        // Assertions, aber der Guard wirft vorher).
        $this->expectException(\RuntimeException::class);
        $executor->execute($this->createTool('read'), ['path' => '../../../etc/passwd']);
    }

    public function testSafePathReadsFileWhenGuardAllows(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'evie_file_guard_');
        file_put_contents($tmp, 'hello sandbox');

        $guard = $this->createMock(SecurityGuard::class);
        $guard->method('isPathSafe')->with($tmp)->willReturn(true);

        $executor = new GenericFileExecutor($guard);
        $result = $executor->execute($this->createTool('read'), ['path' => $tmp]);

        self::assertSame('hello sandbox', $result);
        unlink($tmp);
    }
}
