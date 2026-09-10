<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Skills;

use App\AI\Skills\SubAgentDefinitionLoader;
use App\AI\Skills\SubAgentRegistry;
use App\AI\Skills\SubAgentToolFactory;
use App\AI\Skills\Tool\DynamicTool;
use App\Entity\ToolDefinition;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Vollstaendige Test-Abdeckung fuer SubAgentRegistry.
 *
 * Verifiziert register/unregister, loadAndRegisterApprovedTools
 * (inkl. Fehlerpfad), getTool/getAllTools/getToolDefinition/hasTool und reload.
 */
final class SubAgentRegistryTest extends TestCase
{
    private function createRegistry(
        ?SubAgentToolFactory $factory = null,
        ?SubAgentDefinitionLoader $loader = null
    ): SubAgentRegistry {
        return new SubAgentRegistry(
            $factory ?? $this->createMock(SubAgentToolFactory::class),
            $loader ?? $this->createMock(SubAgentDefinitionLoader::class),
            new NullLogger()
        );
    }

    private function createTool(string $name): DynamicTool
    {
        $tool = $this->createMock(DynamicTool::class);
        $tool->method('getName')->willReturn($name);
        return $tool;
    }

    private function createDefinition(string $name): ToolDefinition
    {
        $def = new ToolDefinition();
        $def->setName($name);
        return $def;
    }

    public function testRegisterAndGetTool(): void
    {
        $registry = $this->createRegistry();
        $tool = $this->createTool('search');
        $def = $this->createDefinition('search');
        $registry->registerTool($tool, $def);

        self::assertSame($tool, $registry->getTool('search'));
        self::assertSame($def, $registry->getToolDefinition('search'));
        self::assertTrue($registry->hasTool('search'));
        self::assertFalse($registry->hasTool('missing'));
        self::assertNull($registry->getTool('missing'));
        self::assertNull($registry->getToolDefinition('missing'));
    }

    public function testRegisterOverwritesExistingTool(): void
    {
        $registry = $this->createRegistry();
        $tool1 = $this->createTool('tool-a');
        $tool2 = $this->createTool('tool-a');
        $registry->registerTool($tool1, $this->createDefinition('tool-a'));
        $registry->registerTool($tool2, $this->createDefinition('tool-a'));

        self::assertSame($tool2, $registry->getTool('tool-a'));
        self::assertCount(1, $registry->getAllTools());
    }

    public function testGetAllToolsReturnsAll(): void
    {
        $registry = $this->createRegistry();
        $registry->registerTool($this->createTool('a'), $this->createDefinition('a'));
        $registry->registerTool($this->createTool('b'), $this->createDefinition('b'));

        self::assertCount(2, $registry->getAllTools());
        self::assertArrayHasKey('a', $registry->getAllTools());
        self::assertArrayHasKey('b', $registry->getAllTools());
    }

    public function testUnregisterExistingTool(): void
    {
        $registry = $this->createRegistry();
        $registry->registerTool($this->createTool('tool-x'), $this->createDefinition('tool-x'));
        self::assertTrue($registry->hasTool('tool-x'));

        $registry->unregisterTool('tool-x');
        self::assertFalse($registry->hasTool('tool-x'));
        self::assertNull($registry->getTool('tool-x'));
        self::assertNull($registry->getToolDefinition('tool-x'));
    }

    public function testUnregisterNonExistentToolDoesNothing(): void
    {
        $registry = $this->createRegistry();
        $registry->unregisterTool('not-registered');
        self::assertFalse($registry->hasTool('not-registered'));
        $this->addToAssertionCount(1);
    }

    public function testLoadAndRegisterApprovedTools(): void
    {
        $def1 = $this->createDefinition('tool-1');
        $def2 = $this->createDefinition('tool-2');

        $loader = $this->createMock(SubAgentDefinitionLoader::class);
        $loader->method('loadAllApproved')->willReturn([$def1, $def2]);

        $tool1 = $this->createTool('tool-1');
        $tool2 = $this->createTool('tool-2');
        $factory = $this->createMock(SubAgentToolFactory::class);
        $factory->method('createFromDefinition')
            ->willReturnOnConsecutiveCalls($tool1, $tool2);

        $registry = $this->createRegistry($factory, $loader);
        $registry->loadAndRegisterApprovedTools();

        self::assertCount(2, $registry->getAllTools());
        self::assertTrue($registry->hasTool('tool-1'));
        self::assertTrue($registry->hasTool('tool-2'));
    }

    public function testLoadAndRegisterApprovedToolsHandlesException(): void
    {
        $def1 = $this->createDefinition('broken');

        $loader = $this->createMock(SubAgentDefinitionLoader::class);
        $loader->method('loadAllApproved')->willReturn([$def1]);

        $factory = $this->createMock(SubAgentToolFactory::class);
        $factory->method('createFromDefinition')
            ->willThrowException(new \RuntimeException('creation failed'));

        $registry = $this->createRegistry($factory, $loader);
        $registry->loadAndRegisterApprovedTools();

        self::assertFalse($registry->hasTool('broken'));
        self::assertCount(0, $registry->getAllTools());
    }

    public function testLoadAndRegisterApprovedToolsEmpty(): void
    {
        $loader = $this->createMock(SubAgentDefinitionLoader::class);
        $loader->method('loadAllApproved')->willReturn([]);

        $registry = $this->createRegistry(null, $loader);
        $registry->loadAndRegisterApprovedTools();
        self::assertCount(0, $registry->getAllTools());
    }

    public function testReloadClearsAndReloads(): void
    {
        $def = $this->createDefinition('reload-tool');
        $loader = $this->createMock(SubAgentDefinitionLoader::class);
        $loader->method('loadAllApproved')->willReturn([$def]);

        $tool = $this->createTool('reload-tool');
        $factory = $this->createMock(SubAgentToolFactory::class);
        $factory->method('createFromDefinition')->willReturn($tool);

        $registry = $this->createRegistry($factory, $loader);
        $registry->registerTool($this->createTool('old-tool'), $this->createDefinition('old-tool'));
        self::assertCount(1, $registry->getAllTools());

        $registry->reload();
        self::assertFalse($registry->hasTool('old-tool'));
        self::assertTrue($registry->hasTool('reload-tool'));
        self::assertCount(1, $registry->getAllTools());
    }
}
