<?php

declare(strict_types=1);

namespace App\Tests\Unit\Event;

use App\Entity\ToolDefinition;
use App\Event\PendingToolApprovalEvent;
use PHPUnit\Framework\TestCase;

/**
 * Vollstaendige Test-Abdeckung fuer PendingToolApprovalEvent.
 */
final class PendingToolApprovalEventTest extends TestCase
{
    public function testGettersWithDefaults(): void
    {
        $def = new ToolDefinition();
        $event = new PendingToolApprovalEvent($def);
        self::assertSame($def, $event->getToolDefinition());
        self::assertNull($event->getUserIdentifier());
        self::assertFalse($event->isApproved());
    }

    public function testGettersWithAllParams(): void
    {
        $def = new ToolDefinition();
        $event = new PendingToolApprovalEvent($def, 'user-42', true);
        self::assertSame($def, $event->getToolDefinition());
        self::assertSame('user-42', $event->getUserIdentifier());
        self::assertTrue($event->isApproved());
    }

    public function testEventNameConstant(): void
    {
        self::assertSame('ai.tool.pending_approval', PendingToolApprovalEvent::NAME);
    }

    public function testIsApprovedFalseWhenNotApproved(): void
    {
        $event = new PendingToolApprovalEvent(new ToolDefinition(), 'user', false);
        self::assertFalse($event->isApproved());
    }
}
