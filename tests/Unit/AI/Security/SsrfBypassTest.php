<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Security;

use App\AI\Security\PolicyDecision;
use App\AI\Security\SecurityGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Result\ToolCall;

/**
 * SSRF-Bypass- und Traversal-Härtungs-Tests (P0-3).
 *
 * Verifiziert, dass nicht-kanonische IP-Formate (Dezimal, Hex, Oktal, kurze
 * Form, IPv4-mapped IPv6) als privat erkannt und geblockt werden. Zudem wird
 * Directory-Traversal ("../") zuverlässig blockt.
 */
final class SsrfBypassTest extends TestCase
{
    private SecurityGuard $guard;

    protected function setUp(): void
    {
        $this->guard = new SecurityGuard(new NullLogger());
    }

    /**
     * @dataProvider nonCanonicalLoopbackProvider
     */
    public function testBlocksNonCanonicalLoopback(string $url): void
    {
        self::assertSame(PolicyDecision::Deny, $this->guard->decide(
            new ToolCall('1', 'http', ['url' => $url]),
            null,
        ));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonCanonicalLoopbackProvider(): array
    {
        return [
            'decimal 2130706433' => ['http://2130706433/admin'],
            'hex 0x7f000001' => ['http://0x7f000001/admin'],
            'octal 0177.0.0.1' => ['http://0177.0.0.1/admin'],
            'short 127.1' => ['http://127.1/admin'],
            'ipv4-mapped ipv6' => ['http://[::ffff:127.0.0.1]/admin'],
        ];
    }

    /**
     * @dataProvider traversalProvider
     */
    public function testBlocksDirectoryTraversal(string $path): void
    {
        self::assertSame(PolicyDecision::Deny, $this->guard->decide(
            new ToolCall('1', 'file_read', ['path' => $path]),
            null,
        ));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function traversalProvider(): array
    {
        return [
            'relative ../etc/passwd' => ['../../etc/passwd'],
            'relative with encoded' => ['%2e%2e%2fetc%2fpasswd'],
            'nested traversal' => ['../../../etc/shadow'],
            'sandbox escape' => ['/tmp/uploads/../../etc/passwd'],
        ];
    }
}
