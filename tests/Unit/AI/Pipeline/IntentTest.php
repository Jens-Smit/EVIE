<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Pipeline;

use App\AI\Pipeline\Intent\Intent;
use PHPUnit\Framework\TestCase;

/**
 * Unit-Tests fuer das Intent-Value-Object (Phase 2).
 *
 * Verifiziert die Exit-Gate-Hilfsmethode isDialog(): conversation und
 * information beenden die Pipeline mit einer direkten Textantwort,
 * task und unclear laufen weiter (Phase 3/4). Keine Mocks noetig.
 *
 * @see docs/architecture/orchestrator-pipeline.md Phase 2
 */
final class IntentTest extends TestCase
{
    public function testConversationIsDialog(): void
    {
        self::assertTrue(Intent::Conversation->isDialog());
    }

    public function testInformationIsDialog(): void
    {
        self::assertTrue(Intent::Information->isDialog());
    }

    public function testTaskIsNotDialog(): void
    {
        self::assertFalse(Intent::Task->isDialog());
    }

    public function testUnclearIsNotDialog(): void
    {
        self::assertFalse(Intent::Unclear->isDialog());
    }

    public function testEnumValuesAreStableStrings(): void
    {
        self::assertSame('conversation', Intent::Conversation->value);
        self::assertSame('information', Intent::Information->value);
        self::assertSame('task', Intent::Task->value);
        self::assertSame('unclear', Intent::Unclear->value);
    }
}
