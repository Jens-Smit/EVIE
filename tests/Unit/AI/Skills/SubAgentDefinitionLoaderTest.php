<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Skills;

use App\AI\Skills\SubAgentDefinitionLoader;
use App\Entity\ToolDefinition;
use App\Repository\ToolDefinitionRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Vollstaendige Test-Abdeckung fuer SubAgentDefinitionLoader.
 */
final class SubAgentDefinitionLoaderTest extends TestCase
{
    private function createLoader(?ToolDefinitionRepository $repo = null): SubAgentDefinitionLoader
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo ?? $this->createMock(ToolDefinitionRepository::class));
        return new SubAgentDefinitionLoader($em);
    }

    public function testLoadFromDatabaseReturnsDefinition(): void
    {
        $def = new ToolDefinition();
        $repo = $this->createMock(ToolDefinitionRepository::class);
        $repo->expects(self::once())->method('find')->with(42)->willReturn($def);

        self::assertSame($def, $this->createLoader($repo)->loadFromDatabase(42));
    }

    public function testLoadFromDatabaseReturnsNullWhenNotFound(): void
    {
        $repo = $this->createMock(ToolDefinitionRepository::class);
        $repo->method('find')->willReturn(null);

        self::assertNull($this->createLoader($repo)->loadFromDatabase(999));
    }

    public function testLoadAllApproved(): void
    {
        $defs = [new ToolDefinition(), new ToolDefinition()];
        $repo = $this->createMock(ToolDefinitionRepository::class);
        $repo->expects(self::once())
            ->method('findBy')
            ->with(['status' => 'approved'])
            ->willReturn($defs);

        self::assertSame($defs, $this->createLoader($repo)->loadAllApproved());
    }

    public function testLoadAllPending(): void
    {
        $defs = [new ToolDefinition()];
        $repo = $this->createMock(ToolDefinitionRepository::class);
        $repo->expects(self::once())
            ->method('findBy')
            ->with(['status' => 'pending'])
            ->willReturn($defs);

        self::assertSame($defs, $this->createLoader($repo)->loadAllPending());
    }

    public function testLoadStaticDefinitionsReturnsEmpty(): void
    {
        self::assertSame([], $this->createLoader()->loadStaticDefinitions());
    }
}
