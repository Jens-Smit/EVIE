<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Skills\Tool;

use App\AI\Skills\Tool\EmailTool;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

final class EmailToolTest extends TestCase
{
    private MailerInterface&MockObject $mailer;
    private EmailTool $tool;

    protected function setUp(): void
    {
        $this->mailer = $this->createMock(MailerInterface::class);
        $this->tool = new EmailTool($this->mailer, 'noreply@evie.ai');
    }

    public function testInvokeReturnsErrorWhenRequiredParamsMissing(): void
    {
        $result = $this->tool->__invoke([]);

        self::assertSame('error', $result['status']);
        self::assertStringContainsString('Fehlende erforderliche', $result['message']);
        self::assertSame(['to', 'subject', 'body'], $result['required']);
        self::assertSame(['from', 'cc', 'bcc', 'is_html'], $result['optional']);
    }

    public function testInvokeReturnsErrorWhenSubjectMissing(): void
    {
        $result = $this->tool->__invoke(['to' => ['a@example.com'], 'body' => 'x']);

        self::assertSame('error', $result['status']);
    }

    public function testInvokeSendsPlainTextEmailAndReturnsSuccess(): void
    {
        $this->mailer->expects(self::once())->method('send')
            ->willReturnCallback(function (Email $email): void {
                self::assertSame('noreply@evie.ai', $email->getFrom()[0]->getAddress());
                self::assertSame('Test Subject', $email->getSubject());
                self::assertSame('alice@example.com', $email->getTo()[0]->getAddress());
            });

        $result = $this->tool->__invoke([
            'to' => ['alice@example.com'],
            'subject' => 'Test Subject',
            'body' => 'Hello plain',
        ]);

        self::assertSame('success', $result['status']);
        self::assertSame(['alice@example.com'], $result['to']);
        self::assertSame('Test Subject', $result['subject']);
        self::assertNotEmpty($result['sent_at']);
    }

    public function testInvokeSendsHtmlEmailWithCustomFromCcBcc(): void
    {
        $this->mailer->expects(self::once())->method('send')
            ->willReturnCallback(function (Email $email): void {
                self::assertSame('custom@evie.ai', $email->getFrom()[0]->getAddress());
                self::assertSame('bob@example.com', $email->getCc()[0]->getAddress());
                self::assertSame('secret@example.com', $email->getBcc()[0]->getAddress());
            });

        $result = $this->tool->__invoke([
            'to' => ['bob@example.com'],
            'subject' => 'HTML',
            'body' => '<p>Hi</p>',
            'from' => 'custom@evie.ai',
            'cc' => 'bob@example.com',
            'bcc' => 'secret@example.com',
            'is_html' => true,
        ]);

        self::assertSame('success', $result['status']);
    }

    public function testInvokeSendsToMultipleRecipients(): void
    {
        $this->mailer->expects(self::once())->method('send')
            ->willReturnCallback(function (Email $email): void {
                self::assertCount(2, $email->getTo());
            });

        $result = $this->tool->__invoke([
            'to' => ['a@example.com', 'b@example.com'],
            'subject' => 'Multi',
            'body' => 'body',
        ]);

        self::assertSame('success', $result['status']);
    }

    public function testInvokeReturnsErrorOnMailerException(): void
    {
        $this->mailer->method('send')->willThrowException(new \RuntimeException('SMTP down'));

        $result = $this->tool->__invoke([
            'to' => ['a@example.com'],
            'subject' => 'Subject',
            'body' => 'Body',
        ]);

        self::assertSame('error', $result['status']);
        self::assertStringContainsString('SMTP down', $result['message']);
        self::assertSame(['a@example.com'], $result['to']);
        self::assertSame('Subject', $result['subject']);
    }
}
