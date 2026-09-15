<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Agent;

use App\AI\Agent\OrchestratorDialogService;
use App\AI\Platform\TenantPlatformContext;
use App\AI\Pipeline\Execution\PipelineResult;
use App\AI\Pipeline\PipelineInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit-Tests fuer OrchestratorDialogService (Blueprint §4.A).
 *
 * Nach Stufe 8 ist OrchestratorDialogService eine duenne Fassade, die
 * ausschliesslich an die Pipeline delegiert. Diese Tests verifizieren,
 * dass ask() die User-Nachricht weiterreicht und
 * PipelineResult::getContent() zurueckliefert.
 */
class OrchestratorAgentTest extends TestCase
{
    public function testAskInvokesAgentAndReturnsString(): void
    {
        $pipeline = $this->createMock(PipelineInterface::class);
        $pipeline->expects(self::once())
            ->method('run')
            ->with('Analysiere diese Daten', 'user123')
            ->willReturn(new PipelineResult(PipelineResult::TYPE_EXECUTED, 'Antwort des Agenten'));

        $orchestrator = new OrchestratorDialogService($pipeline, new TenantPlatformContext());
        $result = $orchestrator->ask('Analysiere diese Daten', 'user123');

        self::assertIsString($result);
        self::assertSame('Antwort des Agenten', $result);
    }

    public function testAskForwardsDifferentPrompts(): void
    {
        $pipeline = $this->createMock(PipelineInterface::class);
        $pipeline->expects(self::once())
            ->method('run')
            ->with('Analysiere diese Excel-Datei', 'user123')
            ->willReturn(new PipelineResult(PipelineResult::TYPE_EXECUTED, 'Excel verarbeitet'));

        $orchestrator = new OrchestratorDialogService($pipeline, new TenantPlatformContext());
        $result = $orchestrator->ask('Analysiere diese Excel-Datei', 'user123');

        self::assertIsString($result);
        self::assertSame('Excel verarbeitet', $result);
    }

    public function testAskHandlesDataAnalysisRequest(): void
    {
        $pipeline = $this->createMock(PipelineInterface::class);
        $pipeline->expects(self::once())
            ->method('run')
            ->willReturn(new PipelineResult(PipelineResult::TYPE_EXECUTED, 'Analyse abgeschlossen'));

        $orchestrator = new OrchestratorDialogService($pipeline, new TenantPlatformContext());
        $result = $orchestrator->ask('Analysiere die Daten', 'user456');

        self::assertIsString($result);
        self::assertNotEmpty($result);
    }

    public function testAskWorksForExcelPrompt(): void
    {
        $pipeline = $this->createMock(PipelineInterface::class);
        $pipeline->expects(self::once())
            ->method('run')
            ->willReturn(new PipelineResult(PipelineResult::TYPE_EXECUTED, 'Excel-Ergebnis'));

        $orchestrator = new OrchestratorDialogService($pipeline, new TenantPlatformContext());
        $result = $orchestrator->ask('Verarbeite Excel', 'user789');

        self::assertIsString($result);
    }
}
