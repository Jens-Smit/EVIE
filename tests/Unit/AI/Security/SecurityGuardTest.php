<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Security;

use App\AI\Security\OutboundRequestPolicy;
use App\AI\Security\SecurityGuard;
use App\AI\Skills\Tool\DynamicTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit-Tests für SecurityGuard (Blueprint §4.E).
 *
 * Testet die reale SecurityGuard-API: Executor-Whitelist, SSRF-Schutz,
 * Pfad-Sandbox, Service-Whitelist (strikt, keine Wildcards) sowie die
 * dynamische Executor-/Service-Verwaltung.
 */
final class SecurityGuardTest extends TestCase
{
    private SecurityGuard $guard;

    protected function setUp(): void
    {
        $this->guard = new SecurityGuard(new NullLogger());
    }

    // ========================================================================
    // isServiceAllowed() — strikte Whitelist, keine Wildcards
    // ========================================================================

    public function testIsServiceAllowedWithListedService(): void
    {
        self::assertTrue($this->guard->isServiceAllowed('App\\AI\\Skills\\Executor\\GenericApiExecutor'));
    }

    public function testIsServiceAllowedRejectsUnlistedService(): void
    {
        self::assertFalse($this->guard->isServiceAllowed('App\\AI\\Skills\\Tool\\CustomTool'));
    }

    public function testIsServiceAllowedRejectsPartialMatch(): void
    {
        // Keine Wildcards: ein Service, der nur den Prefix teilt, ist nicht erlaubt.
        self::assertFalse($this->guard->isServiceAllowed('App\\AI\\Skills\\DangerousExecutor'));
    }

    // ========================================================================
    // isUrlSafe() — SSRF-Schutz
    // ========================================================================

    public function testIsUrlSafeBlocksLocalhost(): void
    {
        self::assertFalse($this->guard->isUrlSafe('http://localhost/api'));
    }

    public function testIsUrlSafeBlocksLoopbackIp(): void
    {
        self::assertFalse($this->guard->isUrlSafe('http://127.0.0.1/api'));
    }

    public function testIsUrlSafeBlocksPrivateRange(): void
    {
        self::assertFalse($this->guard->isUrlSafe('http://192.168.1.1/secret'));
        self::assertFalse($this->guard->isUrlSafe('http://10.0.0.1/internal'));
    }

    public function testIsUrlSafeAllowsPublicUrl(): void
    {
        self::assertTrue($this->guard->isUrlSafe('https://api.example.com/data'));
    }

    // ========================================================================
    // isPathSafe() — Pfad-Sandbox
    // ========================================================================

    public function testIsPathSafeBlocksEtc(): void
    {
        self::assertFalse($this->guard->isPathSafe('/etc/passwd'));
    }

    public function testIsPathSafeBlocksRootAndProc(): void
    {
        self::assertFalse($this->guard->isPathSafe('/root/.ssh/id_rsa'));
        self::assertFalse($this->guard->isPathSafe('/proc/1/cmdline'));
    }

    public function testIsPathSafeAllowsSandboxPath(): void
    {
        self::assertTrue($this->guard->isPathSafe('/tmp/uploads/data.csv'));
    }

    // ========================================================================
    // isToolSafe() — Executor-Whitelist via DynamicTool
    // ========================================================================

    public function testIsToolSafeAllowsGenericExecutor(): void
    {
        $tool = new DynamicTool('safe_tool', 'desc', [], 'generic');
        self::assertTrue($this->guard->isToolSafe($tool));
    }

    public function testIsToolSafeBlocksUnknownExecutor(): void
    {
        $tool = new DynamicTool('shell_tool', 'desc', [], 'shell');
        self::assertFalse($this->guard->isToolSafe($tool));
    }

    public function testIsToolSafeBlocksExplicitlyBlockedPolicy(): void
    {
        $tool = new DynamicTool('blocked_tool', 'desc', [], 'generic', [], ['allowed' => false]);
        self::assertFalse($this->guard->isToolSafe($tool));
    }

    // ========================================================================
    // Dynamische Verwaltung
    // ========================================================================

    public function testAddAllowedExecutorAddsNewExecutor(): void
    {
        $this->guard->addAllowedExecutor('custom');
        $executors = $this->guard->getAllowedExecutors();

        self::assertContains('custom', $executors);
    }

    public function testAddAllowedExecutorDoesNotDuplicate(): void
    {
        $this->guard->addAllowedExecutor('api');
        $count = count(array_filter($this->guard->getAllowedExecutors(), static fn (string $e): bool => $e === 'api'));

        self::assertSame(1, $count);
    }

    public function testAddBlockedResourceAppends(): void
    {
        $this->guard->addBlockedResource('evil.example.com');
        self::assertContains('evil.example.com', $this->guard->getBlockedResources());
    }

    public function testAddAndRemoveAllowedService(): void
    {
        $this->guard->addAllowedService('App\\Custom\\Service');
        self::assertTrue($this->guard->isServiceAllowed('App\\Custom\\Service'));

        $this->guard->removeAllowedService('App\\Custom\\Service');
        self::assertFalse($this->guard->isServiceAllowed('App\\Custom\\Service'));
    }

    // ========================================================================
    // Getter
    // ========================================================================

    public function testGetAllowedExecutorsContainsCoreTypes(): void
    {
        $executors = $this->guard->getAllowedExecutors();

        self::assertContains('api', $executors);
        self::assertContains('database', $executors);
        self::assertContains('filesystem', $executors);
        self::assertContains('http', $executors);
        self::assertContains('generic', $executors);
    }

    // ========================================================================
    // isUrlSafe() — Defense-in-Depth via OutboundRequestPolicy (P0-3)
    // ========================================================================

    /**
     * Beweist, dass eine injizierte OutboundRequestPolicy tatsächlich
     * konsultiert wird: eine URL, die die String-basierte Prüfung passiert
     * (kein privater Host-String), wird geblockt, sobald die Policy die URL
     * ablehnt (z. B. weil die Domain auf eine private IP auflöst).
     */
    public function testIsUrlSafeConsultsInjectedOutboundRequestPolicy(): void
    {
        $policy = $this->createStub(OutboundRequestPolicy::class);
        $policy->method('isUrlAllowed')->willReturn(false);

        $guard = new SecurityGuard(new NullLogger(), $policy);

        // 'https://example.com/data' würde ohne Policy durchgehen; mit Policy
        // (die hier hart verweigert) muss es geblockt werden.
        self::assertFalse($guard->isUrlSafe('https://example.com/data'));
    }

    /**
     * Ohne injizierte Policy bleibt das bisherige String-basierte Verhalten
     * erhalten (Backward-Kompatibilität für Tests/CLI).
     */
    public function testIsUrlSafeWithoutPolicyKeepsStringBasedBehavior(): void
    {
        $guard = new SecurityGuard(new NullLogger(), null);

        self::assertTrue($guard->isUrlSafe('https://example.com/data'));
        self::assertFalse($guard->isUrlSafe('http://127.0.0.1/admin'));
    }
}
