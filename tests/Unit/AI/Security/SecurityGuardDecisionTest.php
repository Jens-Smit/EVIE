<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Security;

use App\AI\Security\PolicyDecision;
use App\AI\Security\SecurityGuard;
use App\Entity\ToolDefinition;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Result\ToolCall;

/**
 * Unit-Tests für die native SecurityGuard::decide() (Blueprint §4.E).
 *
 * Verifiziert die PolicyDecision Allow / Deny / AskUser anhand der nativen
 * ToolCall-Argumente und der ToolDefinition.
 */
final class SecurityGuardDecisionTest extends TestCase
{
    private SecurityGuard $guard;

    protected function setUp(): void
    {
        $this->guard = new SecurityGuard(new NullLogger());
    }

    public function testAllowsStaticToolWithoutDefinition(): void
    {
        $toolCall = new ToolCall('1', 'weather', ['city' => 'Berlin']);

        self::assertSame(PolicyDecision::Allow, $this->guard->decide($toolCall, null));
    }

    public function testDeniesSsrfLocalhostUrl(): void
    {
        $toolCall = new ToolCall('1', 'http_call', ['url' => 'http://127.0.0.1/admin']);

        self::assertSame(PolicyDecision::Deny, $this->guard->decide($toolCall, null));
    }

    public function testDeniesSsrfPrivateIpRange(): void
    {
        $toolCall = new ToolCall('1', 'http_call', ['url' => 'http://192.168.1.1/secret']);

        self::assertSame(PolicyDecision::Deny, $this->guard->decide($toolCall, null));
    }

    public function testDeniesBlockedPathEtc(): void
    {
        $toolCall = new ToolCall('1', 'file_read', ['path' => '/etc/passwd']);

        self::assertSame(PolicyDecision::Deny, $this->guard->decide($toolCall, null));
    }

    public function testAllowsSafeUrlAndPath(): void
    {
        $toolCall = new ToolCall('1', 'http_call', ['url' => 'https://example.com/api']);

        self::assertSame(PolicyDecision::Allow, $this->guard->decide($toolCall, null));
    }

    public function testDeniesUnknownExecutorType(): void
    {
        $definition = (new ToolDefinition())
            ->setName('shell_tool')
            ->setExecutorType('shell');

        $toolCall = new ToolCall('1', 'shell_tool', []);

        self::assertSame(PolicyDecision::Deny, $this->guard->decide($toolCall, $definition));
    }

    public function testAskUserForHighSecurityLevel(): void
    {
        $definition = (new ToolDefinition())
            ->setName('sensitive')
            ->setExecutorType('api')
            ->setSecurityLevel('high');

        $toolCall = new ToolCall('1', 'sensitive', []);

        self::assertSame(PolicyDecision::AskUser, $this->guard->decide($toolCall, $definition));
    }

    public function testAskUserForRequiresHitl(): void
    {
        $definition = (new ToolDefinition())
            ->setName('hitl_tool')
            ->setExecutorType('generic')
            ->setRequiresHitl(true);

        $toolCall = new ToolCall('1', 'hitl_tool', []);

        self::assertSame(PolicyDecision::AskUser, $this->guard->decide($toolCall, $definition));
    }

    public function testAllowsApprovedLowRiskDynamicTool(): void
    {
        $definition = (new ToolDefinition())
            ->setName('safe')
            ->setExecutorType('generic')
            ->setSecurityLevel('low');

        $toolCall = new ToolCall('1', 'safe', []);

        self::assertSame(PolicyDecision::Allow, $this->guard->decide($toolCall, $definition));
    }

    public function testDecidesDenyForArrayArgumentsWithSsrf(): void
    {
        $toolCall = new ToolCall('1', 'batch', ['endpoints' => ['https://localhost/exploit']]);

        self::assertSame(PolicyDecision::Deny, $this->guard->decide($toolCall, null));
    }
}
