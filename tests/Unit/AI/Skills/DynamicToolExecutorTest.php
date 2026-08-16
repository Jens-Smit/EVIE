<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Skills;

use App\AI\Skills\Executor\ExecutorInterface;
use App\AI\Skills\Executor\ExecutorResolverInterface;
use App\AI\Skills\Tool\DynamicTool;
use App\AI\Skills\Tool\DynamicToolExecutor;
use App\AI\Skills\Tool\ToolExecutionResult;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit-Tests fuer die Tool-Ausfuehrung (Blueprint §4.F).
 *
 * Verifiziert, dass der DynamicToolExecutor nach HITL-Freigabe ein Tool ueber
 * den ExecutorResolver ausfuehrt und das Ergebnis kapselt. Fehler im Executor
 * werden als fehlgeschlagenes ToolExecutionResult gemeldet (kein Absturz).
 *
 * Dieser Test deckt den Ausfuehrungs-Pfad ab, der NACH der Toolfreigabe laeuft:
 * generiert -> pending -> approved -> ausgefuehrt.
 */
final class DynamicToolExecutorTest extends TestCase
{
    private ExecutorResolverInterface&MockObject $resolver;
    private DynamicToolExecutor $executor;

    protected function setUp(): void
    {
        $this->resolver = $this->createMock(ExecutorResolverInterface::class);
        $this->executor = new DynamicToolExecutor($this->resolver, new NullLogger());
    }

    public function testExecuteResolvesExecutorAndReturnsSuccessResult(): void
    {
        $tool = new DynamicTool(
            'web_scraper',
            'Scraped Webseiten',
            ['type' => 'object', 'properties' => ['url' => ['type' => 'string']]],
            'generic'
        );

        $concreteExecutor = $this->createMock(ExecutorInterface::class);
        $concreteExecutor->expects(self::once())->method('execute')
            ->with($tool, ['url' => 'https://example.com'])
            ->willReturn(['content' => 'Beispielinhalt']);

        $this->resolver->expects(self::once())->method('resolve')
            ->with('generic')
            ->willReturn($concreteExecutor);

        $result = $this->executor->execute($tool, ['url' => 'https://example.com']);

        self::assertTrue($result->isSuccess());
        self::assertSame('web_scraper', $result->getToolName());
        self::assertSame(['content' => 'Beispielinhalt'], $result->getResult());
        self::assertNull($result->getErrorMessage());
    }

    public function testExecuteWithoutExecutorTypeReturnsFailure(): void
    {
        $tool = new DynamicTool('orphan_tool', null, [], null);

        // Resolver wird nicht aufgerufen, da Executor-Type fehlt.
        $this->resolver->expects(self::never())->method('resolve');

        $result = $this->executor->execute($tool, []);

        self::assertFalse($result->isSuccess());
        self::assertNotNull($result->getErrorMessage());
        self::assertStringContainsString('Executor-Type', $result->getErrorMessage());
    }

    public function testExecuteHandlesExecutorExceptionGracefully(): void
    {
        $tool = new DynamicTool('failing_tool', 'Fehlerhaft', [], 'api');

        $concreteExecutor = $this->createMock(ExecutorInterface::class);
        $concreteExecutor->method('execute')
            ->willThrowException(new \RuntimeException('API unreachable'));

        $this->resolver->method('resolve')->willReturn($concreteExecutor);

        $result = $this->executor->execute($tool, ['endpoint' => '/v1/data']);

        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('API unreachable', $result->getErrorMessage() ?? '');
        self::assertSame('failing_tool', $result->getToolName());
    }

    public function testIsExecutableChecksResolverSupport(): void
    {
        $tool = new DynamicTool('supported_tool', null, [], 'filesystem');

        $this->resolver->method('supports')
            ->willReturnMap([
                ['filesystem', true],
                ['shell', false],
            ]);

        self::assertTrue($this->executor->isExecutable($tool));

        $shellTool = new DynamicTool('shell_tool', null, [], 'shell');
        self::assertFalse($this->executor->isExecutable($shellTool));
    }
}
