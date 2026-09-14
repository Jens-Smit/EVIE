<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Skills\Tool;

use App\AI\Skills\Tool\ToolInterface;
use App\AI\Skills\Tool\ToolRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

/**
 * Unit-Tests fuer ToolRegistry.
 *
 * Deckt beide Tool-Arten ab, die vom Symfony AI Bundle mit dem Tag
 * ai.tool versehen werden:
 *  - native #[AsTool]-Tools (ohne ToolInterface, ohne getName()),
 *  - custom ToolInterface-Implementoren (DynamicTool, GenericToolExecutor).
 *
 * Der Regression-Test testAllIncludesNativeAsToolTools() reproduziert den
 * UndefinedMethodError, der auftrat, als der Planner (Phase 3) toolRegistry
 * ->all() aufrief und ein #[AsTool]-Tool ohne getName() traf.
 */
final class ToolRegistryTest extends TestCase
{
    public function testAllIncludesNativeAsToolTools(): void
    {
        $registry = new ToolRegistry([
            new NativeAsToolStub(),
            new SecondNativeAsToolStub(),
        ]);

        $tools = $registry->all();

        self::assertArrayHasKey('data_analyzer', $tools);
        self::assertArrayHasKey('weather', $tools);
    }

    public function testAllIncludesToolInterfaceImplementors(): void
    {
        $registry = new ToolRegistry([
            $this->buildToolInterface('generic_tool_executor'),
        ]);

        $tools = $registry->all();

        self::assertArrayHasKey('generic_tool_executor', $tools);
    }

    public function testAllMixesNativeAsToolAndToolInterface(): void
    {
        $registry = new ToolRegistry([
            new NativeAsToolStub(),
            $this->buildToolInterface('generic_tool_executor'),
        ]);

        $tools = $registry->all();

        self::assertCount(2, $tools);
        self::assertArrayHasKey('data_analyzer', $tools);
        self::assertArrayHasKey('generic_tool_executor', $tools);
    }

    public function testHasFindsNativeAsToolByName(): void
    {
        $registry = new ToolRegistry([new NativeAsToolStub()]);

        self::assertTrue($registry->has('data_analyzer'));
        self::assertFalse($registry->has('missing_tool'));
    }

    public function testGetReturnsToolInterfaceForImplementor(): void
    {
        $tool = $this->buildToolInterface('generic_tool_executor');
        $registry = new ToolRegistry([$tool]);

        self::assertSame($tool, $registry->get('generic_tool_executor'));
    }

    public function testGetThrowsForNativeAsToolWithoutToolInterface(): void
    {
        $registry = new ToolRegistry([new NativeAsToolStub()]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('kein ToolInterface');

        $registry->get('data_analyzer');
    }

    public function testGetThrowsForUnknownTool(): void
    {
        $registry = new ToolRegistry([]);

        $this->expectException(\InvalidArgumentException::class);
        $registry->get('unknown');
    }

    private function buildToolInterface(string $name): ToolInterface
    {
        return new class($name) implements ToolInterface {
            public function __construct(private readonly string $name)
            {
            }

            public function __invoke(array $parameters = []): array
            {
                return [];
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function getDescription(): string
            {
                return '';
            }
        };
    }
}

#[AsTool('data_analyzer', 'Analysiert Daten.')]
final class NativeAsToolStub
{
    public function __invoke(array $data): string
    {
        return '';
    }
}

#[AsTool('weather', 'Liefert Wetterdaten.')]
final class SecondNativeAsToolStub
{
    public function __invoke(array $data): string
    {
        return '';
    }
}
