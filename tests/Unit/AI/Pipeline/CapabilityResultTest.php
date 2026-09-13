<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Pipeline;

use App\AI\Pipeline\Capability\CapabilityDecision;
use App\AI\Pipeline\Capability\CapabilityResult;
use PHPUnit\Framework\TestCase;

/**
 * Unit-Tests fuer CapabilityDecision und CapabilityResult (Phase 4).
 *
 * Verifiziert die drei Entscheidungs-States und dass ein Ergebnis eine
 * Optionale ExecutionReference bzw. ToolDefinition-ID tragen kann.
 *
 * @see docs/architecture/orchestrator-pipeline.md Phase 4
 */
final class CapabilityResultTest extends TestCase
{
    public function testAvailableDecision(): void
    {
        self::assertTrue(CapabilityDecision::Available->isAvailable());
        self::assertFalse(CapabilityDecision::Available->isMissing());
        self::assertFalse(CapabilityDecision::Available->isPending());
    }

    public function testMissingDecision(): void
    {
        self::assertTrue(CapabilityDecision::Missing->isMissing());
        self::assertFalse(CapabilityDecision::Missing->isAvailable());
    }

    public function testPendingDecision(): void
    {
        self::assertTrue(CapabilityDecision::Pending->isPending());
        self::assertFalse(CapabilityDecision::Pending->isAvailable());
    }

    public function testAvailableResultCarriesExecutionReference(): void
    {
        $reference = new \stdClass();
        $result = new CapabilityResult(CapabilityDecision::Available, $reference);

        self::assertSame($reference, $result->getExecutionReference());
        self::assertNull($result->getToolDefinitionId());
    }

    public function testPendingResultCarriesToolDefinitionId(): void
    {
        $result = new CapabilityResult(CapabilityDecision::Pending, null, 42);

        self::assertNull($result->getExecutionReference());
        self::assertSame(42, $result->getToolDefinitionId());
    }
}
