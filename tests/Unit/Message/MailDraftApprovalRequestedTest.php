<?php

declare(strict_types=1);

namespace App\Tests\Unit\Message;

use App\Message\MailDraftApprovalRequested;
use PHPUnit\Framework\TestCase;

final class MailDraftApprovalRequestedTest extends TestCase
{
    public function testGettersWithDefaults(): void
    {
        $message = new MailDraftApprovalRequested(
            'Subject',
            ['alice@example.com'],
            'Body text',
            [],
            'user-1'
        );

        self::assertSame('Subject', $message->getSubject());
        self::assertSame(['alice@example.com'], $message->getRecipients());
        self::assertSame('Body text', $message->getContent());
        self::assertSame([], $message->getAttachments());
        self::assertSame('user-1', $message->getRequestedBy());
    }

    public function testGettersWithAttachments(): void
    {
        $message = new MailDraftApprovalRequested(
            'Report',
            ['bob@example.com', 'carol@example.com'],
            'See attached',
            ['report.pdf', 'data.csv'],
            'user-2'
        );

        self::assertSame('Report', $message->getSubject());
        self::assertSame(['bob@example.com', 'carol@example.com'], $message->getRecipients());
        self::assertSame('See attached', $message->getContent());
        self::assertSame(['report.pdf', 'data.csv'], $message->getAttachments());
        self::assertSame('user-2', $message->getRequestedBy());
    }
}
