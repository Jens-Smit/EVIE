<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Pipeline;

use App\AI\Pipeline\Execution\PipelineResult;
use PHPUnit\Framework\TestCase;

/**
 * Unit-Tests fuer das PipelineResult-Value-Object (Phase 5).
 *
 * Verifiziert die Ergebnis-Typen und die optionale ToolDefinition-ID,
 * die den HITL-Freigabe-Link identifiziert.
 *
 * @see docs/architecture/orchestrator-pipeline.md Phase 5
 */
final class PipelineResultTest extends TestCase
{
    public function testDialogResult(): void
    {
        $result = new PipelineResult(PipelineResult::TYPE_DIALOG, 'Hallo, ich bin EVIE.');

        self::assertSame(PipelineResult::TYPE_DIALOG, $result->getType());
        self::assertSame('Hallo, ich bin EVIE.', $result->getContent());
        self::assertNull($result->getToolDefinitionId());
    }

    public function testAwaitingApprovalResultCarriesToolDefinitionId(): void
    {
        $result = new PipelineResult(PipelineResult::TYPE_AWAITING_APPROVAL, 'Freigabe noetig', 7);

        self::assertSame(PipelineResult::TYPE_AWAITING_APPROVAL, $result->getType());
        self::assertSame(7, $result->getToolDefinitionId());
    }

    public function testExecutedResult(): void
    {
        $result = new PipelineResult(PipelineResult::TYPE_EXECUTED, 'Ausgefuehrt');

        self::assertSame(PipelineResult::TYPE_EXECUTED, $result->getType());
    }
}
