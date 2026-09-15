<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Agent;

use App\AI\Agent\OrchestratorDialogService;
use App\AI\Pipeline\Execution\PipelineResult;
use App\AI\Pipeline\PipelineInterface;
use App\AI\Platform\TenantPlatformContext;
use PHPUnit\Framework\TestCase;

/**
 * Unit-Tests fuer die OrchestratorDialogService-Fassade (Blueprint §4.A).
 *
 * Nach Stufe 8 ist OrchestratorDialogService eine duenne Fassade, die
 * ausschliesslich an PipelineInterface::run() delegiert. ask() setzt
 * zusaetzlich den Tenant-Kontext fuer den TenantAwarePlatform-Decorator.
 * Die konkrete Dispatch-Logik (tool_call / subagent_delegation / dialog /
 * no_tool_found / unclear) liegt in den Pipeline-Phasen-Tests
 * (IntentClassifierTest, PlannerTest, CapabilityResolverTest,
 * ExecutionCoordinatorTest, PipelineTest). Diese Tests verifizieren
 * lediglich, dass ask() das Pipeline-Ergebnis korrekt durchreicht.
 */
final class OrchestratorAgentLlmTest extends TestCase
{
    public function testDialogResultContentIsReturned(): void
    {
        $pipeline = $this->createMock(PipelineInterface::class);
        $pipeline->expects(self::once())
            ->method('run')
            ->with('Hallo', 'user-789')
            ->willReturn(new PipelineResult(PipelineResult::TYPE_DIALOG, 'Hallo, ich kann dir helfen.'));

        $orchestrator = new OrchestratorDialogService($pipeline, new TenantPlatformContext());
        $result = $orchestrator->ask('Hallo', 'user-789');

        self::assertIsString($result);
        self::assertSame('Hallo, ich kann dir helfen.', $result);
    }

    public function testExecutedResultContentIsReturned(): void
    {
        $pipeline = $this->createMock(PipelineInterface::class);
        $pipeline->expects(self::once())
            ->method('run')
            ->willReturn(new PipelineResult(PipelineResult::TYPE_EXECUTED, 'Datenanalyse abgeschlossen: 42 Verkaeufe'));

        $orchestrator = new OrchestratorDialogService($pipeline, new TenantPlatformContext());
        $result = $orchestrator->ask('Analysiere die Daten', 'user-456');

        self::assertStringContainsString('Datenanalyse', $result);
    }

    public function testAwaitingApprovalResultContentIsReturned(): void
    {
        $pipeline = $this->createMock(PipelineInterface::class);
        $pipeline->expects(self::once())
            ->method('run')
            ->willReturn(new PipelineResult(PipelineResult::TYPE_AWAITING_APPROVAL, 'Tool wartet auf Freigabe', 42));

        $orchestrator = new OrchestratorDialogService($pipeline, new TenantPlatformContext());
        $result = $orchestrator->ask('Rufe die API example.com auf', 'user-gen');

        self::assertIsString($result);
    }

    public function testClarifyResultContentIsReturned(): void
    {
        $pipeline = $this->createMock(PipelineInterface::class);
        $pipeline->expects(self::once())
            ->method('run')
            ->willReturn(new PipelineResult(PipelineResult::TYPE_CLARIFY, 'Bitte beschreibe es etwas genauer.'));

        $orchestrator = new OrchestratorDialogService($pipeline, new TenantPlatformContext());
        $result = $orchestrator->ask('irgendwas', 'user-unclear');

        self::assertStringContainsString('genauer', $result);
    }
}
