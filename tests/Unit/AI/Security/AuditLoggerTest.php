<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Security;

use App\AI\Security\AuditLogger;
use App\Entity\AuditLog;
use App\Entity\User;
use App\Repository\AuditLogRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Vollstaendige Test-Abdeckung fuer AuditLogger (P0-9 Observability).
 *
 * Verifiziert, dass jede log*-Methode die korrekte Action, den Entity-Typ,
 * Status und Kontext (inkl. IP/User-Agent/User-Email) an das Repository
 * weitergibt und dass Secrets redigiert werden.
 */
final class AuditLoggerTest extends TestCase
{
    public function testLogWithoutRequest(): void
    {
        $repo = $this->createMock(AuditLogRepository::class);
        $expectedLog = new AuditLog();
        $repo->expects(self::once())
            ->method('log')
            ->with(
                'custom_action',
                null,
                42,
                'SomeEntity',
                self::callback(function (array $ctx): bool {
                    return $ctx['user_email'] === null
                        && $ctx['ip_address'] === null
                        && $ctx['user_agent'] === null
                        && $ctx['extra'] === 'value';
                }),
                'success',
                'detail'
            )
            ->willReturn($expectedLog);

        $logger = new AuditLogger($repo, new RequestStack());
        $result = $logger->log('custom_action', null, 42, 'SomeEntity', ['extra' => 'value'], 'success', 'detail');

        self::assertSame($expectedLog, $result);
    }

    public function testLogWithUserAndRequestExtractsMetadata(): void
    {
        $user = $this->createUser(7, 'alice@example.com');

        $request = Request::create('/path', 'GET', [], [], [], [
            'REMOTE_ADDR' => '203.0.113.5',
            'HTTP_USER_AGENT' => 'UnitTest/1.0',
        ]);

        $repo = $this->createMock(AuditLogRepository::class);
        $expectedLog = new AuditLog();
        $repo->expects(self::once())
            ->method('log')
            ->with(
                'custom_action',
                7,
                null,
                null,
                self::callback(function (array $ctx): bool {
                    return $ctx['user_email'] === 'alice@example.com'
                        && $ctx['ip_address'] === '203.0.113.5'
                        && $ctx['user_agent'] === 'UnitTest/1.0';
                }),
                'failure',
                'something failed'
            )
            ->willReturn($expectedLog);

        $stack = new RequestStack();
        $stack->push($request);

        $logger = new AuditLogger($repo, $stack);
        $result = $logger->log('custom_action', $user, null, null, [], 'failure', 'something failed');

        self::assertSame($expectedLog, $result);
    }

    public function testLogToolRegistrationSuccess(): void
    {
        $repo = $this->createMock(AuditLogRepository::class);
        $expectedLog = new AuditLog();
        $repo->expects(self::once())
            ->method('log')
            ->with('tool_registration', null, 11, 'ToolDefinition', self::callback(fn (array $c) => $c['tool_name'] === 'web_search'), 'success', null)
            ->willReturn($expectedLog);

        $logger = new AuditLogger($repo, new RequestStack());
        self::assertSame($expectedLog, $logger->logToolRegistration(11, 'web_search', null, true));
    }

    public function testLogToolRegistrationFailure(): void
    {
        $repo = $this->createMock(AuditLogRepository::class);
        $expectedLog = new AuditLog();
        $repo->expects(self::once())
            ->method('log')
            ->with('tool_registration', null, 11, 'ToolDefinition', self::callback(fn (array $c) => $c['tool_name'] === 'web_search'), 'failure', 'boom')
            ->willReturn($expectedLog);

        $logger = new AuditLogger($repo, new RequestStack());
        self::assertSame($expectedLog, $logger->logToolRegistration(11, 'web_search', null, false, 'boom'));
    }

    public function testLogToolExecutionIncludesParameters(): void
    {
        $repo = $this->createMock(AuditLogRepository::class);
        $expectedLog = new AuditLog();
        $repo->expects(self::once())
            ->method('log')
            ->with(
                'tool_execution',
                null,
                5,
                'ToolDefinition',
                self::callback(fn (array $c) => $c['tool_name'] === 'fetch' && $c['url'] === 'https://x.test'),
                'success',
                null
            )
            ->willReturn($expectedLog);

        $logger = new AuditLogger($repo, new RequestStack());
        self::assertSame($expectedLog, $logger->logToolExecution(5, 'fetch', null, true, null, ['url' => 'https://x.test']));
    }

    public function testLogHitlDecision(): void
    {
        $repo = $this->createMock(AuditLogRepository::class);
        $expectedLog = new AuditLog();
        $repo->expects(self::once())
            ->method('log')
            ->with(
                'hitl_decision',
                null,
                9,
                'ToolDefinition',
                self::callback(fn (array $c) => $c['tool_name'] === 'danger' && $c['decision'] === 'approved'),
                'success',
                'reason'
            )
            ->willReturn($expectedLog);

        $logger = new AuditLogger($repo, new RequestStack());
        self::assertSame($expectedLog, $logger->logHitlDecision(9, 'danger', null, 'approved', 'reason'));
    }

    public function testLogSecurityViolation(): void
    {
        $repo = $this->createMock(AuditLogRepository::class);
        $expectedLog = new AuditLog();
        $repo->expects(self::once())
            ->method('log')
            ->with(
                'security_violation',
                null,
                null,
                null,
                self::callback(function (array $c): bool {
                    self::assertSame('v', $c['violation_key']);
                    return true;
                }),
                'failure',
                'ssrf: attempted internal host'
            )
            ->willReturn($expectedLog);

        $logger = new AuditLogger($repo, new RequestStack());
        self::assertSame($expectedLog, $logger->logSecurityViolation('ssrf', null, 'attempted internal host', ['violation_key' => 'v']));
    }

    public function testLogAuthenticationAttemptSuccess(): void
    {
        $repo = $this->createMock(AuditLogRepository::class);
        $expectedLog = new AuditLog();
        $repo->expects(self::once())
            ->method('log')
            ->with(
                'authentication',
                null,
                null,
                'User',
                self::callback(function (array $c): bool {
                    self::assertSame('1.2.3.4', $c['ip_address']);
                    return true;
                }),
                'success',
                null
            )
            ->willReturn($expectedLog);

        $logger = new AuditLogger($repo, new RequestStack());
        self::assertSame($expectedLog, $logger->logAuthenticationAttempt(null, true, null, '1.2.3.4'));
    }

    public function testLogAuthenticationAttemptFailure(): void
    {
        $repo = $this->createMock(AuditLogRepository::class);
        $expectedLog = new AuditLog();
        $repo->expects(self::once())
            ->method('log')
            ->with('authentication', null, null, 'User', self::callback(fn (array $c) => $c['ip_address'] === null), 'failure', 'bad credentials')
            ->willReturn($expectedLog);

        $logger = new AuditLogger($repo, new RequestStack());
        self::assertSame($expectedLog, $logger->logAuthenticationAttempt(null, false, 'bad credentials'));
    }

    public function testLogApiCallRedactsParameters(): void
    {
        $repo = $this->createMock(AuditLogRepository::class);
        $expectedLog = new AuditLog();
        $repo->expects(self::once())
            ->method('log')
            ->with(
                'api_call',
                null,
                null,
                null,
                self::callback(function (array $c): bool {
                    return $c['endpoint'] === '/api/x'
                        && $c['parameters']['password'] === '***REDACTED***'
                        && $c['parameters']['q'] === 'term';
                }),
                'success',
                null
            )
            ->willReturn($expectedLog);

        $logger = new AuditLogger($repo, new RequestStack());
        self::assertSame($expectedLog, $logger->logApiCall('/api/x', null, true, ['password' => 'secret', 'q' => 'term']));
    }

    public function testLogPolicyDecisionRedactsArguments(): void
    {
        $repo = $this->createMock(AuditLogRepository::class);
        $expectedLog = new AuditLog();
        $repo->expects(self::once())
            ->method('log')
            ->with(
                'policy_decision',
                null,
                null,
                'ToolDefinition',
                self::callback(function (array $c): bool {
                    return $c['tool_name'] === 'tool'
                        && $c['decision'] === 'deny'
                        && $c['arguments']['token'] === '***REDACTED***';
                }),
                'success',
                'blocked'
            )
            ->willReturn($expectedLog);

        $logger = new AuditLogger($repo, new RequestStack());
        self::assertSame($expectedLog, $logger->logPolicyDecision('tool', 'deny', null, ['token' => 'jwt'], 'blocked'));
    }

    private function createUser(int $id, string $email): User
    {
        $user = new User();
        $user->setEmail($email);
        $ref = new \ReflectionClass(User::class);
        $prop = $ref->getProperty('id');
        $prop->setValue($user, $id);

        return $user;
    }
}
