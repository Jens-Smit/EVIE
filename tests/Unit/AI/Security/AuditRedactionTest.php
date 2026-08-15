<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Security;

use App\AI\Security\AuditLogger;
use App\Entity\AuditLog;
use App\Repository\AuditLogRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Secret-Redaction-Tests (P0-9).
 *
 * Verifiziert, dass sensible Tool-Parameter (Passwörter, API-Keys, Tokens)
 * vor dem Audit-Logging redigiert werden, sodass Secrets niemals im
 * Audit-Log landen.
 */
final class AuditRedactionTest extends TestCase
{
    public function testRedactsPasswordAndApiKey(): void
    {
        $auditLogger = $this->buildAuditLogger();

        $redacted = $auditLogger->redact([
            'username' => 'alice',
            'password' => 'super-secret-123',
            'api_key' => 'sk-abc123',
        ]);

        self::assertSame('alice', $redacted['username']);
        self::assertSame('***REDACTED***', $redacted['password']);
        self::assertSame('***REDACTED***', $redacted['api_key']);
    }

    public function testRedactsNestedSecrets(): void
    {
        $auditLogger = $this->buildAuditLogger();

        $redacted = $auditLogger->redact([
            'config' => [
                'authorization' => 'Bearer xyz',
                'public_field' => 'visible',
            ],
            'token' => 'jwt-token-here',
        ]);

        self::assertSame('***REDACTED***', $redacted['config']['authorization']);
        self::assertSame('visible', $redacted['config']['public_field']);
        self::assertSame('***REDACTED***', $redacted['token']);
    }

    public function testLeavesNonSensitiveDataIntact(): void
    {
        $auditLogger = $this->buildAuditLogger();

        $redacted = $auditLogger->redact([
            'city' => 'Berlin',
            'limit' => 10,
            'url' => 'https://example.com',
        ]);

        self::assertSame('Berlin', $redacted['city']);
        self::assertSame(10, $redacted['limit']);
        self::assertSame('https://example.com', $redacted['url']);
    }

    private function buildAuditLogger(): AuditLogger
    {
        $auditRepo = $this->createMock(AuditLogRepository::class);
        $auditRepo->method('log')->willReturn(new AuditLog());

        return new AuditLogger($auditRepo, new RequestStack());
    }
}
