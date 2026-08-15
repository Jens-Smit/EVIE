<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Skills\Tool;

use App\AI\Skills\Executor\ExecutorInterface;
use App\AI\Skills\Executor\ExecutorResolverInterface;
use App\AI\Skills\Tool\DynamicTool;
use App\AI\Skills\Tool\DynamicToolExecutor;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit-Tests für DynamicToolExecutor (Blueprint §4.F).
 *
 * Verifiziert die Tool-Ausführung über den ExecutorResolver:
 *  - erfolgreiche Ausführung mit gelistetem Executor-Typ
 *  - Fehler bei fehlendem Executor-Typ
 *  - Fehler-Weiterleitung bei Executor-Exception
 */
final class DynamicToolExecutorTest extends TestCase
{
    private ExecutorResolverInterface $resolver;
    private DynamicToolExecutor $executor;

    protected function setUp(): void
    {
        $this->resolver = $this->createMock(ExecutorResolverInterface::class);
        $this->executor = new DynamicToolExecutor($this->resolver, new NullLogger());
    }

    public function testExecuteRunsViaResolvedExecutor(): void
    {
        $tool = new DynamicTool('csv_analyzer', 'desc', [], 'filesystem');

        $genericExecutor = $this->createMock(ExecutorInterface::class);
        $genericExecutor->expects(self::once())
            ->method('execute')
            ->with(self::identicalTo($tool), ['path' => '/tmp/data.csv'])
            ->willReturn(['rows' => 42]);

        $this->resolver->expects(self::once())
            ->method('resolve')
            ->with('filesystem')
            ->willReturn($genericExecutor);

        $result = $this->executor->execute($tool, ['path' => '/tmp/data.csv']);

        self::assertTrue($result->isSuccess());
        self::assertSame('csv_analyzer', $result->getToolName());
        self::assertSame(['rows' => 42], $result->getResult());
    }

    public function testExecuteFailsWithoutExecutorType(): void
    {
        $tool = new DynamicTool('no_executor', 'desc', [], null);

        $result = $this->executor->execute($tool, []);

        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('Kein Executor-Type', $result->getErrorMessage() ?? '');
    }

    public function testExecuteCatchesExecutorException(): void
    {
        $tool = new DynamicTool('failing_tool', 'desc', [], 'api');

        $genericExecutor = $this->createMock(ExecutorInterface::class);
        $genericExecutor->method('execute')
            ->willThrowException(new \RuntimeException('API offline'));

        $this->resolver->method('resolve')->with('api')->willReturn($genericExecutor);

        $result = $this->executor->execute($tool, []);

        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('API offline', $result->getErrorMessage() ?? '');
    }
}
