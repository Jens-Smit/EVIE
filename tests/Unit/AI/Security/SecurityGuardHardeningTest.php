<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Security;

use App\AI\Security\AuditLogger;
use App\AI\Security\OutboundRequestPolicy;
use App\AI\Security\PolicyDecision;
use App\AI\Security\SecurityGuard;
use App\Entity\AuditLog;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Result\ToolCall;

/**
 * SecurityGuard-Hardening-Tests fuer die offenen Production-Readiness-Punkte:
 *  - DNS-Rebinding/Redirect-Schutz (3.9-3.12): Hostnamen, die zu privaten IPs
 *    aufloesen, werden ueber die OutboundRequestPolicy geblockt.
 *  - Command-Chaining/Shell-Injection (3.30-3.32): Argumente mit Shell-
 *    Metazeichen (&&, ;, |, $(), ${}) werden geblockt.
 *  - Audit-Anbindung (9.8/9.12): ein Policy-Deny wird via AuditLogger
 *    protokolliert (logSecurityViolation).
 */
final class SecurityGuardHardeningTest extends TestCase
{
    /**
     * Hostname, der zu 127.0.0.1 aufloest, muss geblockt werden (DNS-Rebinding).
     *
     * Der Mock bildet gethostbynamel() nach: ein Hostname, der auf eine private
     * IP aufloest, wird von OutboundRequestPolicy::isUrlAllowed() abgelehnt.
     */
    public function testBlocksDnsRebindingHostnameResolvingToPrivateIp(): void
    {
        $policy = $this->createMock(OutboundRequestPolicy::class);
        $policy->method('isUrlAllowed')
            ->willReturn(false);

        $guard = new SecurityGuard(new NullLogger(), $policy);

        self::assertSame(
            PolicyDecision::Deny,
            $guard->decide(new ToolCall('1', 'http', ['url' => 'http://evil.example.com/steal']), null),
        );
    }

    /**
     * Ein oeffentlicher Hostname, der nicht zu einem privaten Netzwerk
     * aufloest, bleibt erlaubt (kein False-Positive).
     */
    public function testAllowsPublicHostnameResolvingToPublicIp(): void
    {
        $policy = $this->createMock(OutboundRequestPolicy::class);
        $policy->method('isUrlAllowed')
            ->willReturn(true);

        $guard = new SecurityGuard(new NullLogger(), $policy);

        self::assertSame(
            PolicyDecision::Allow,
            $guard->decide(new ToolCall('1', 'http', ['url' => 'https://example.com/api']), null),
        );
    }

    /**
     * @dataProvider shellMetacharProvider
     */
    public function testBlocksShellMetacharactersInArguments(string $value): void
    {
        // Ohne OutboundRequestPolicy, damit nur der Shell-Meta-Check greift.
        $guard = new SecurityGuard(new NullLogger());

        self::assertSame(
            PolicyDecision::Deny,
            $guard->decide(new ToolCall('1', 'exec', ['cmd' => $value]), null),
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function shellMetacharProvider(): array
    {
        return [
            'command chaining &&' => ['ls && rm -rf /'],
            'semicolon chaining' => ['cat /etc/passwd; whoami'],
            'pipe chaining' => ['cat /etc/passwd | grep root'],
            'double pipe' => ['false || true'],
            'command substitution' => ['echo $(whoami)'],
            'variable expansion' => ['echo ${PATH}'],
            'dollar variable' => ['echo $HOME'],
            'backticks' => ['echo `whoami`'],
            'url-encoded chaining' => ['ls %26%26 rm -rf /'],
            'newline injection' => ["ls\nwhoami"],
        ];
    }

    /**
     * Normale Werte ohne Shell-Metazeichen bleiben erlaubt (kein False-Positive).
     */
    public function testAllowsArgumentsWithoutShellMetacharacters(): void
    {
        $guard = new SecurityGuard(new NullLogger());

        self::assertSame(
            PolicyDecision::Allow,
            $guard->decide(new ToolCall('1', 'weather', ['city' => 'Berlin']), null),
        );
    }

    /**
     * Ein Policy-Deny (hier: SSRF) wird via AuditLogger protokolliert (P0-9).
     */
    public function testPolicyDenyIsAudited(): void
    {
        $auditLogger = $this->createMock(AuditLogger::class);
        $auditLogger->expects(self::once())
            ->method('logSecurityViolation')
            ->with(
                'tool_policy_deny',
                self::isNull(),
                self::stringContains('SecurityGuard blockiert'),
                self::callback(static function (array $context): bool {
                    return 'ssrf' === ($context['violation'] ?? null);
                }),
            )
            ->willReturn($this->createStub(AuditLog::class));

        $guard = new SecurityGuard(new NullLogger(), null, $auditLogger);

        self::assertSame(
            PolicyDecision::Deny,
            $guard->decide(new ToolCall('1', 'http', ['url' => 'http://127.0.0.1/admin']), null),
        );
    }

    /**
     * Ohne injizierten AuditLogger wird ein Deny nicht protokolliert, wirft
     * aber auch keinen Fehler (Abwaertskompatibilitaet fuer Tests/CLI).
     */
    public function testPolicyDenyWithoutAuditLoggerDoesNotFail(): void
    {
        $guard = new SecurityGuard(new NullLogger());

        self::assertSame(
            PolicyDecision::Deny,
            $guard->decide(new ToolCall('1', 'http', ['url' => 'http://127.0.0.1/admin']), null),
        );
    }
}
