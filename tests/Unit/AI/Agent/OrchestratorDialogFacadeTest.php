<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Agent;

use App\AI\Agent\OrchestratorDialogService;
use App\AI\Pipeline\Execution\PipelineResult;
use App\AI\Pipeline\PipelineInterface;
use App\AI\Platform\TenantPlatformContext;
use PHPUnit\Framework\TestCase;

/**
 * Unit-Test fuer die OrchestratorDialogService-Fassade: ask() delegiert
 * ausschliesslich an PipelineInterface::run() und liefert
 * PipelineResult::getContent(). Der alte reaktive Legacy-Pfad ist
 * entfernt (Stufe 8). ask() setzt zusaetzlich den Tenant-Kontext, damit
 * der TenantAwarePlatform-Decorator den pro-Tenant API-Key aufloest.
 *
 * @see docs/architecture/orchestrator-pipeline.md Phase 8
 */
final class OrchestratorDialogFacadeTest extends TestCase
{
    public function testAskDelegatesToPipelineAndReturnsContent(): void
    {
        $pipeline = $this->createMock(PipelineInterface::class);
        $pipeline->expects(self::once())
            ->method('run')
            ->with('moin', 'user-1')
            ->willReturn(new PipelineResult(PipelineResult::TYPE_DIALOG, 'Hallo von der Pipeline'));

        $service = new OrchestratorDialogService($pipeline, new TenantPlatformContext());
        $result = $service->ask('moin', 'user-1');
        self::assertSame('Hallo von der Pipeline', $result);
    }

    public function testAskReturnsExecutedResultContent(): void
    {
        $pipeline = $this->createMock(PipelineInterface::class);
        $pipeline->expects(self::once())
            ->method('run')
            ->willReturn(new PipelineResult(PipelineResult::TYPE_EXECUTED, 'Ergebnis der Ausfuehrung'));

        $service = new OrchestratorDialogService($pipeline, new TenantPlatformContext());
        $result = $service->ask('Rufe API auf', 'user-2');
        self::assertSame('Ergebnis der Ausfuehrung', $result);
    }
}
