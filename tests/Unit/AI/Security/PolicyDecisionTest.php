<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Security;

use App\AI\Security\PolicyDecision;
use PHPUnit\Framework\TestCase;

/**
 * Unit-Tests fuer PolicyDecision-Enum.
 */
final class PolicyDecisionTest extends TestCase
{
    public function testHasThreeCases(): void
    {
        self::assertSame(
            ['Allow', 'Deny', 'AskUser'],
            array_column(PolicyDecision::cases(), 'name'),
        );
    }

    public function testEachCaseIsAccessible(): void
    {
        self::assertSame('Allow', PolicyDecision::Allow->name);
        self::assertSame('Deny', PolicyDecision::Deny->name);
        self::assertSame('AskUser', PolicyDecision::AskUser->name);
    }
}
