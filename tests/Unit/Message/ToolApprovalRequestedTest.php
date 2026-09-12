<?php

declare(strict_types=1);

namespace App\Tests\Unit\Message;

use App\Message\ToolApprovalRequested;
use PHPUnit\Framework\TestCase;

final class ToolApprovalRequestedTest extends TestCase
{
    public function testGetters(): void
    {
        $message = new ToolApprovalRequested(
            42,
            'web_search',
            'Search the web',
            ['type' => 'object'],
            'user-1'
        );

        self::assertSame(42, $message->getToolId());
        self::assertSame('web_search', $message->getToolName());
        self::assertSame('Search the web', $message->getDescription());
        self::assertSame(['type' => 'object'], $message->getSchema());
        self::assertSame('user-1', $message->getRequestedBy());
    }
}
