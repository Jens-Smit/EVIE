<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Pipeline;

use App\AI\Pipeline\Execution\ExecutionState;
use PHPUnit\Framework\TestCase;

/**
 * Unit-Tests fuer den ExecutionState (Phase 5 Workflow-Store).
 *
 * Der State haelt die Ergebnisse abgeschlossener Schritte und stellt sie
 * nachfolgenden Schritten als Input bereit — der fehlende Datenfluss
 * zwischen den Agenten eines Workflows.
 *
 * @see docs/architecture/orchestrator-pipeline.md Phase 5
 */
final class ExecutionStateTest extends TestCase
{
    public function testSetAndGetStoresResultUnderKey(): void
    {
        $state = new ExecutionState();
        $state->set('market_research', ['findings' => ['a']]);

        self::assertSame(['findings' => ['a']], $state->get('market_research'));
        self::assertTrue($state->has('market_research'));
    }

    public function testGetReturnsNullForUnknownKey(): void
    {
        $state = new ExecutionState();

        self::assertNull($state->get('unbekannt'));
        self::assertFalse($state->has('unbekannt'));
    }

    public function testCollectReturnsOnlyExistingKeys(): void
    {
        $state = new ExecutionState();
        $state->set('research', 'Recherche-Ergebnis');
        $state->set('analysis', ['summary' => 'ok']);

        $collected = $state->collect(['research', 'fehlt', 'analysis']);

        self::assertSame(
            ['research' => 'Recherche-Ergebnis', 'analysis' => ['summary' => 'ok']],
            $collected
        );
    }

    public function testAllReturnsCompleteResultMap(): void
    {
        $state = new ExecutionState();
        $state->set('a', 1);
        $state->set('b', 2);

        self::assertSame(['a' => 1, 'b' => 2], $state->all());
    }
}
