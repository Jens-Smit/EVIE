<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Skills\Tool;

use App\AI\Skills\Tool\AttributeToolAdapter;
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
 *
 * testGetReturnsAdapterForNativeAsToolTool() und
 * testAdapterDelegatesInvocationToNativeTool() decken den Fix aus
 * dev-tail.log ab: Der Planner kuendigt native #[AsTool]-Tools im Plan an,
 * ToolRegistry::get() liefert sie jetzt als AttributeToolAdapter, sodass
 * Phase 5 (ToolStepExecutor) statische Tool-Schritte ausfuehren kann,
 * statt mit "ist kein ToolInterface" abzubrechen.
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
            new Nat
iveAsToolStub(),
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

    public function testGetReturnsAdapterForNativeAsToolTool(): void
    {
        $registry = new ToolRegistry([new NativeAsToolStub()]);

        $tool = $registry->get('data_analyzer');

        self::assertInstanceOf(AttributeToolAdapter::class, $tool);
        self::assertInstanceOf(ToolInterface::class, $tool);
        self::assertSame('data_analyzer', $tool->getName());
        self::assertSame('Analysiert Daten.', $tool->getDescription());
    }

    public function testAdapterDelegatesInvocationToNativeTool(): void
    {
        $registry = new ToolRegistry([new NativeAsToolStub()]);
        $tool = $registry->get('data_analyzer');

        $result = $tool(['value' => 42]);

        self::assertSame(['result' => 'echo:42'], $result);
    }

    public function testGetThrowsForUnknownTool(): void
    {
        $registry = new ToolRegistry([]);

        $this->expectException(\InvalidArgumentException::class);
        $registry->get('unknown');
    }


    /**
     * Regression-Test fuer den e2e-llm-Fehlschlag
     * (SetupTaskAutonomousE2ETest): Der Adapter uebergab die komplette
     * Parameter-Map als erstes Argument an das native Tool. Tools mit
     * scalaren Signatur-Parametern (hier: string $userIdentifier, wie
     * UserTypeLookupTool) scheiterten mit einem TypeError. Der Adapter
     * muss die Map-Schluessel per Name auf die Signatur abbilden und
     * Schluessel ohne Gegenstueck (z.B. 'input_from') verwerfen.
     */
    public function testAdapterMapsNamedParametersOntoScalarSignature(): void
    {
        $registry = new ToolRegistry([new ScalarSignatureAsToolStub()]);
        $tool = $registry->get('user_type_lookup');

        $result = $tool(['user_identifier' => 'user-7', 'input_from' => ['ignored']]);

        self::assertSame(['result' => 'type:user-7'], $result);
    }

    private function buildToolInterface(string $name): ToolInterface
    {
        return new class($name) implements ToolInterface {
            public function __construct(private readonly string $n
ame)
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
        return 'echo:' . ($data['value'] ?? '');
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

#[AsTool('user_type_lookup', 'Liefert den Nutzertyp.')]
final class ScalarSignatureAsToolStub
{
    public function __invoke(string $userIdentifier): string
    {
        return 'type:' . $userIdentifier;
    }
}
