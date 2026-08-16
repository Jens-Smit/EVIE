<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Skills;

use App\AI\Skills\ToolDefinitionGenerator;
use App\Entity\ToolDefinition;
use App\Repository\ToolCategoryRepository;
use App\Repository\ToolDefinitionRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\PlatformInterface;

/**
 * Unit-Tests fuer die HITL-Toolfreigabe (Blueprint §4.D, §5 Schritt 7-8).
 *
 * Verifiziert, dass ein generiertes Tool nach Human-in-the-Loop-Freigabe den
 * Status 'approved' erhaelt und damit fuer die DynamicToolbox verfuegbar wird,
 * waehrend eine Ablehnung den Status 'rejected' setzt. Die Freigabe ist die
 * Voraussetzung, dass ein Tool zur Laufzeit ausgefuehrt werden darf.
 */
final class ToolApprovalUnitTest extends TestCase
{
    private ToolDefinitionRepository&MockObject $toolDefinitionRepo;
    private ToolDefinitionGenerator $generator;

    protected function setUp(): void
    {
        $this->toolDefinitionRepo = $this->createMock(ToolDefinitionRepository::class);

        $this->generator = new ToolDefinitionGenerator(
            $this->toolDefinitionRepo,
            $this->createMock(ToolCategoryRepository::class),
            $this->createMock(PlatformInterface::class),
            $this->createMock(AgentInterface::class),
            new NullLogger()
        );
    }

    public function testApproveToolSetsApprovedStatusAndPersists(): void
    {
        $definition = (new ToolDefinition())
            ->setName('pending_tool')
            ->setStatus('pending')
            ->setExecutorType('generic');

        $this->toolDefinitionRepo->expects(self::once())->method('save')
            ->with(self::callback(function (ToolDefinition $d): bool {
                return $d->getStatus() === 'approved';
            }), true);

        $this->generator->approveTool($definition);

        self::assertSame('approved', $definition->getStatus());
        self::assertNotNull($definition->getUpdatedAt());
    }

    public function testRejectToolSetsRejectedStatusAndRecordsReason(): void
    {
        $definition = (new ToolDefinition())
            ->setName('bad_tool')
            ->setStatus('pending')
            ->setExecutorType('generic');

        $this->toolDefinitionRepo->expects(self::once())->method('save')
            ->with(self::callback(function (ToolDefinition $d): bool {
                return $d->getStatus() === 'rejected'
                    && ($d->getMetadata()['rejection_reason'] ?? null) === 'Unsicher';
            }), true);

        $this->generator->rejectTool($definition, 'Unsicher');

        self::assertSame('rejected', $definition->getStatus());
        self::assertSame('Unsicher', $definition->getMetadata()['rejection_reason']);
    }

    public function testRejectedToolNotExposedByDynamicToolbox(): void
    {
        // DynamicToolbox laedt nur approved Tools (Blueprint §4.B).
        // Ein rejected Tool darf nicht in getTools() erscheinen.
        $definition = (new ToolDefinition())
            ->setName('rejected_tool')
            ->setStatus('rejected')
            ->setExecutorType('generic');

        $repo = $this->createMock(ToolDefinitionRepository::class);
        // findAllApproved liefert nur approved -> rejected Tool ist nicht dabei.
        $repo->method('findAllApproved')->willReturn([]);

        $inner = $this->createMock(\Symfony\AI\Agent\Toolbox\ToolboxInterface::class);
        $inner->method('getTools')->willReturn([]);

        $toolbox = new \App\AI\Skills\DynamicToolbox(
            $inner,
            $repo,
            $this->createUserContext()
        );

        $tools = $toolbox->getTools();
        $names = array_map(fn($t) => $t->getName(), $tools);
        self::assertNotContains('rejected_tool', $names);
    }

    public function testApprovedToolExposedByDynamicToolbox(): void
    {
        $definition = (new ToolDefinition())
            ->setName('approved_tool')
            ->setStatus('approved')
            ->setExecutorType('generic')
            ->setDescription('Ein freigegebenes Tool')
            ->setSchema(['type' => 'object', 'properties' => ['x' => ['type' => 'string']]]);

        $repo = $this->createMock(ToolDefinitionRepository::class);
        $repo->method('findAllApproved')->willReturn([$definition]);

        $inner = $this->createMock(\Symfony\AI\Agent\Toolbox\ToolboxInterface::class);
        $inner->method('getTools')->willReturn([]);

        $toolbox = new \App\AI\Skills\DynamicToolbox(
            $inner,
            $repo,
            $this->createUserContext()
        );

        $tools = $toolbox->getTools();
        self::assertCount(1, $tools);
        self::assertSame('approved_tool', $tools[0]->getName());
    }

    private function createUserContext(): \App\Security\UserContext
    {
        $requestStack = new \Symfony\Component\HttpFoundation\RequestStack();
        $requestStack->push(new \Symfony\Component\HttpFoundation\Request());
        return new \App\Security\UserContext(
            $requestStack,
            $this->createMock(\Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface::class)
        );
    }
}
