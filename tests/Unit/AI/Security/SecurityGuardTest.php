<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Security;

use App\AI\Security\OutboundRequestPolicy;
use App\AI\Security\PolicyDecision;
use App\AI\Security\SecurityGuard;
use App\AI\Skills\Tool\DynamicTool;
use App\Entity\ToolDefinition;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Result\ToolCall;

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

    public function testIsUrlSafeExpandsShortFormLargeOctetToPublic(): void
    {
        // 1.2130706433 (2 Oktetts, letztes > 255) expandiert via while-loop zu 1.1.0.0 (public).
        self::assertTrue($this->guard->isUrlSafe('http://1.2130706433/x'));
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

    // ========================================================================
    // isUrlSafe() — Private-IP-Erkennung jenseits der String-Blocklist
    // ========================================================================

    public function testIsUrlSafeBlocksUniqueLocalIpv6ViaPrivateIpCheck(): void
    {
        // fd00::1 ist eine ULA, die nicht in der String-Blocklist (fc00::)
        // steht, also erst durch isPrivateIp() geblockt wird.
        self::assertFalse($this->guard->isUrlSafe('http://[fd00::1]/x'));
    }

    public function testIsUrlSafeBlocksPrivateIpv4ViaNormalizedDecimal(): void
    {
        // 172.17.0.1 = 2886795265 (Dezimal) liegt im privaten 172.16/12-Range,
        // wird aber nicht von der String-Blocklist '172.16.' erfasst, sodass
        // der ip2long-basierte IPv4-Check greift.
        self::assertFalse($this->guard->isUrlSafe('http://2886795265/x'));
    }

    // ========================================================================
    // isPathSafe() — realpath-basierte Symlink-Erkennung
    // ========================================================================

    public function testIsPathSafeBlocksExistingPathResolvingToBlockedRealpath(): void
    {
        // Erzeuge eine existierende Datei in /tmp, die via realpath auf einen
        // geblockten Pfad (/etc) zeigt. realpath() != original -> Blocked-Check.
        $tmpLink = sys_get_temp_dir() . '/evie_securityguard_test_' . uniqid('', true);
        $target = '/etc/hosts';
        @symlink($target, $tmpLink);
        if (!is_link($tmpLink) && !file_exists($tmpLink)) {
            self::markTestSkipped('Symlink konnte nicht erstellt werden.');
        }
        try {
            self::assertFalse($this->guard->isPathSafe($tmpLink));
        } finally {
            @unlink($tmpLink);
        }
    }

    public function testIsPathSafeAllowsExistingNonBlockedPath(): void
    {
        // Ein existierender Pfad, dessen realpath nicht geblockt ist -> true.
        $safe = sys_get_temp_dir();
        self::assertTrue($this->guard->isPathSafe($safe));
    }

    // ========================================================================
    // decide() — Policy-Entscheidung für Tool-Calls (P1-3 / P1-4)
    // ========================================================================

    private function createToolCall(array $arguments = []): ToolCall
    {
        return new ToolCall('call-1', 'tool_name', $arguments);
    }

    private function createDefinition(?string $executorType = null, ?bool $requiresHitl = null, ?string $securityLevel = null): ToolDefinition
    {
        $def = new ToolDefinition();
        if ($executorType !== null) {
            $def->setExecutorType($executorType);
        }
        if ($requiresHitl !== null) {
            $def->setRequiresHitl($requiresHitl);
        }
        if ($securityLevel !== null) {
            $def->setSecurityLevel($securityLevel);
        }
        return $def;
    }

    public function testDecideAllowsWithNoDefinitionAndSafeArgs(): void
    {
        $decision = $this->guard->decide($this->createToolCall(['arg' => 'value']));
        self::assertSame(PolicyDecision::Allow, $decision);
    }

    public function testDecideAllowsWithSafeDefinition(): void
    {
        $def = $this->createDefinition('http');
        $decision = $this->guard->decide($this->createToolCall(), $def);
        self::assertSame(PolicyDecision::Allow, $decision);
    }

    public function testDecideDeniesUnknownExecutorType(): void
    {
        $def = $this->createDefinition('unknown_executor');
        $decision = $this->guard->decide($this->createToolCall(), $def);
        self::assertSame(PolicyDecision::Deny, $decision);
    }

    public function testDecideDeniesWhenExecutorServiceNotAllowed(): void
    {
        // 'filesystem' resolves to GenericFileExecutor; remove it from allowlist
        $this->guard->removeAllowedService('App\\AI\\Skills\\Executor\\GenericFileExecutor');
        $def = $this->createDefinition('filesystem');
        $decision = $this->guard->decide($this->createToolCall(), $def);
        self::assertSame(PolicyDecision::Deny, $decision);
    }

    public function testDecideDeniesUnsafeUrlInArguments(): void
    {
        $decision = $this->guard->decide($this->createToolCall(['url' => 'http://127.0.0.1/admin']));
        self::assertSame(PolicyDecision::Deny, $decision);
    }

    public function testDecideDeniesUnsafePathInArguments(): void
    {
        $decision = $this->guard->decide($this->createToolCall(['path' => '/etc/passwd']));
        self::assertSame(PolicyDecision::Deny, $decision);
    }

    public function testDecideDeniesShellMetacharactersInArguments(): void
    {
        $decision = $this->guard->decide($this->createToolCall(['cmd' => 'ls; rm -rf /']));
        self::assertSame(PolicyDecision::Deny, $decision);
    }

    public function testDecideAskUserWhenRequiresHitlTrue(): void
    {
        $def = $this->createDefinition('http', true);
        $decision = $this->guard->decide($this->createToolCall(['safe' => 'arg']), $def);
        self::assertSame(PolicyDecision::AskUser, $decision);
    }

    public function testDecideAskUserWhenSecurityLevelHigh(): void
    {
        $def = $this->createDefinition('http', null, 'high');
        $decision = $this->guard->decide($this->createToolCall(['safe' => 'arg']), $def);
        self::assertSame(PolicyDecision::AskUser, $decision);
    }

    public function testDecideDeniesShellMetacharactersBacktick(): void
    {
        $decision = $this->guard->decide($this->createToolCall(['x' => '`whoami`']));
        self::assertSame(PolicyDecision::Deny, $decision);
    }

    public function testDecideDeniesShellMetacharactersDollarParen(): void
    {
        $decision = $this->guard->decide($this->createToolCall(['x' => '$(cat /etc/passwd)']));
        self::assertSame(PolicyDecision::Deny, $decision);
    }

    public function testDecideDeniesShellMetacharactersDollarBrace(): void
    {
        $decision = $this->guard->decide($this->createToolCall(['x' => '${IFS}']));
        self::assertSame(PolicyDecision::Deny, $decision);
    }

    public function testDecideDeniesPipeCommandInjection(): void
    {
        $decision = $this->guard->decide($this->createToolCall(['x' => 'cat file | grep secret']));
        self::assertSame(PolicyDecision::Deny, $decision);
    }

    public function testDecideDeniesAndOperator(): void
    {
        $decision = $this->guard->decide($this->createToolCall(['x' => 'true && whoami']));
        self::assertSame(PolicyDecision::Deny, $decision);
    }

    public function testDecideDeniesOrOperator(): void
    {
        $decision = $this->guard->decide($this->createToolCall(['x' => 'false || whoami']));
        self::assertSame(PolicyDecision::Deny, $decision);
    }

    public function testDecideHandlesNestedArrayArguments(): void
    {
        $decision = $this->guard->decide($this->createToolCall([
            'options' => ['nested' => 'http://127.0.0.1/secret'],
        ]));
        self::assertSame(PolicyDecision::Deny, $decision);
    }

    public function testDecideAllowsSafeUrl(): void
    {
        $decision = $this->guard->decide($this->createToolCall(['url' => 'https://example.com/api']));
        self::assertSame(PolicyDecision::Allow, $decision);
    }

    public function testDecideAllowsNonStringArguments(): void
    {
        $decision = $this->guard->decide($this->createToolCall(['num' => 42, 'bool' => true]));
        self::assertSame(PolicyDecision::Allow, $decision);
    }

    public function testDecideAllowsWhenDefinitionNullButNoDangerousArgs(): void
    {
        $decision = $this->guard->decide($this->createToolCall(['query' => 'search term']), null);
        self::assertSame(PolicyDecision::Allow, $decision);
    }

    // ========================================================================
    // containsShellMetacharacters() — public helper (P1-3)
    // ========================================================================

    public function testContainsShellMetacharactersFalseForNormalString(): void
    {
        self::assertFalse($this->guard->containsShellMetacharacters('normal search term'));
    }

    public function testContainsShellMetacharactersFalseForUrl(): void
    {
        self::assertFalse($this->guard->containsShellMetacharacters('https://example.com/path?q=1&b=2'));
    }

    public function testContainsShellMetacharactersFalseForTemplateString(): void
    {
        self::assertFalse($this->guard->containsShellMetacharacters('Hello {name}!'));
    }

    public function testContainsShellMetacharactersTrueForSemicolon(): void
    {
        self::assertTrue($this->guard->containsShellMetacharacters('cmd; rm'));
    }

    public function testContainsShellMetacharactersTrueForBacktick(): void
    {
        self::assertTrue($this->guard->containsShellMetacharacters('`id`'));
    }

    public function testContainsShellMetacharactersTrueForDollarParen(): void
    {
        self::assertTrue($this->guard->containsShellMetacharacters('$(whoami)'));
    }

    // ========================================================================
    // isUrlSafe / isPathSafe additional coverage
    // ========================================================================

    public function testIsUrlSafeBlocksMetadataHost(): void
    {
        self::assertFalse($this->guard->isUrlSafe('http://169.254.169.254/latest/meta-data/'));
    }

    public function testIsUrlSafeBlocksBlockedResourceDomain(): void
    {
        $this->guard->addBlockedResource('evil.com');
        self::assertFalse($this->guard->isUrlSafe('https://evil.com/attack'));
    }

    public function testIsUrlSafeAllowsWhenNoBlockedMatch(): void
    {
        $this->guard->addBlockedResource('evil.com');
        self::assertTrue($this->guard->isUrlSafe('https://safe-site.org/data'));
    }

    public function testIsPathSafeBlocksProc(): void
    {
        self::assertFalse($this->guard->isPathSafe('/proc/self/environ'));
    }

    public function testIsPathSafeBlocksUrlEncodedTraversal(): void
    {
        self::assertFalse($this->guard->isPathSafe('%2e%2e%2fetc'));
    }

    public function testIsPathSafeAllowsSafeRelativePath(): void
    {
        self::assertTrue($this->guard->isPathSafe('uploads/documents/file.txt'));
    }

    public function testIsResourceBlocked(): void
    {
        // isResourceBlocked checks URL/path safety, not blocked-resources list
        self::assertTrue($this->guard->isResourceBlocked('http://127.0.0.1/admin'));
        self::assertFalse($this->guard->isResourceBlocked('https://safe-site.org/data'));
    }

    public function testIsResourceBlockedForUnsafePath(): void
    {
        self::assertTrue($this->guard->isResourceBlocked('/etc/passwd'));
    }

    public function testIsResourceBlockedForSafePath(): void
    {
        self::assertFalse($this->guard->isResourceBlocked('uploads/file.txt'));
    }

    // ========================================================================
    // isUrlSafe — SSRF-Normalisierung und private IP-Erkennung
    // ========================================================================

    public function testIsUrlSafeBlocksDecimalIp(): void
    {
        // 2130706433 = 127.0.0.1 in dezimal
        self::assertFalse($this->guard->isUrlSafe('http://2130706433/admin'));
    }

    public function testIsUrlSafeBlocksHexIp(): void
    {
        // 0x7f000001 = 127.0.0.1 in hex
        self::assertFalse($this->guard->isUrlSafe('http://0x7f000001/admin'));
    }

    public function testIsUrlSafeBlocksOctalIp(): void
    {
        // 0177.0.0.1 = 127.0.0.1 in oktal
        self::assertFalse($this->guard->isUrlSafe('http://0177.0.0.1/admin'));
    }

    public function testIsUrlSafeBlocksShortFormIp(): void
    {
        // 127.1 = 127.0.0.1 in kurzer Form
        self::assertFalse($this->guard->isUrlSafe('http://127.1/admin'));
    }

    public function testIsUrlSafeBlocksIpv4MappedIpv6(): void
    {
        self::assertFalse($this->guard->isUrlSafe('http://[::ffff:127.0.0.1]/admin'));
    }

    public function testIsUrlSafeBlocksIpv6Loopback(): void
    {
        self::assertFalse($this->guard->isUrlSafe('http://[::1]/admin'));
    }

    public function testIsUrlSafeBlocksIpv6LinkLocal(): void
    {
        self::assertFalse($this->guard->isUrlSafe('http://[fe80::1]/admin'));
    }

    public function testIsUrlSafeBlocksIpv6UniqueLocal(): void
    {
        self::assertFalse($this->guard->isUrlSafe('http://[fc00::1]/admin'));
        self::assertFalse($this->guard->isUrlSafe('http://[fd00::1]/admin'));
    }

    public function testIsUrlSafeBlocksPrivate10Range(): void
    {
        self::assertFalse($this->guard->isUrlSafe('http://10.0.0.1/internal'));
    }

    public function testIsUrlSafeBlocksPrivate172Range(): void
    {
        self::assertFalse($this->guard->isUrlSafe('http://172.16.0.1/internal'));
    }

    public function testIsUrlSafeBlocksZeroIp(): void
    {
        self::assertFalse($this->guard->isUrlSafe('http://0.0.0.0/admin'));
    }

    public function testIsUrlSafeAllowsPublicIpv6(): void
    {
        self::assertTrue($this->guard->isUrlSafe('http://[2001:4860:4860::8888]/dns'));
    }

    public function testIsUrlSafeBlocksBlockedResourceSubstring(): void
    {
        $this->guard->addBlockedResource('malicious');
        self::assertFalse($this->guard->isUrlSafe('https://malicious-site.com/attack'));
    }

    public function testIsUrlSafeAllowsUrlWithPort(): void
    {
        self::assertTrue($this->guard->isUrlSafe('https://example.com:8080/api'));
    }

    // ========================================================================
    // isPathSafe — Traversal and realpath edge cases
    // ========================================================================

    public function testIsPathSafeBlocksDoubleDot(): void
    {
        self::assertFalse($this->guard->isPathSafe('safe/../etc/passwd'));
    }

    public function testIsPathSafeBlocksRootBlockedPath(): void
    {
        self::assertFalse($this->guard->isPathSafe('/root/secret'));
    }

    public function testIsPathSafeBlocksVarPath(): void
    {
        self::assertFalse($this->guard->isPathSafe('/var/log/app.log'));
    }

    public function testIsPathSafeBlocksDevPath(): void
    {
        self::assertFalse($this->guard->isPathSafe('/dev/null'));
    }

    public function testIsPathSafeBlocksHomePath(): void
    {
        self::assertFalse($this->guard->isPathSafe('/home/user/.ssh'));
    }

    public function testIsPathSafeAllowsTempFile(): void
    {
        // /tmp is not in blocked list
        self::assertTrue($this->guard->isPathSafe('/tmp/evie-cache/file.txt'));
    }

    public function testIsPathSafeAllowsRelativeUpload(): void
    {
        self::assertTrue($this->guard->isPathSafe('uploads/images/photo.jpg'));
    }

    // ========================================================================
    // isToolSafe — SecurityPolicy edge cases
    // ========================================================================

    public function testIsToolSafeAllowsWhenSecurityPolicyAllowedTrue(): void
    {
        $tool = $this->createMock(DynamicTool::class);
        $tool->method('getName')->willReturn('safe-tool');
        $tool->method('getExecutorType')->willReturn('http');
        $tool->method('getSecurityPolicy')->willReturn(['allowed' => true]);
        self::assertTrue($this->guard->isToolSafe($tool));
    }

    public function testIsToolSafeAllowsWhenNoSecurityPolicy(): void
    {
        $tool = $this->createMock(DynamicTool::class);
        $tool->method('getName')->willReturn('safe-tool');
        $tool->method('getExecutorType')->willReturn('http');
        $tool->method('getSecurityPolicy')->willReturn([]);
        self::assertTrue($this->guard->isToolSafe($tool));
    }

    public function testGetBlockedResources(): void
    {
        $this->guard->addBlockedResource('a.com');
        $this->guard->addBlockedResource('b.com');
        $resources = $this->guard->getBlockedResources();
        self::assertContains('a.com', $resources);
        self::assertContains('b.com', $resources);
    }

    public function testGetAllowedServicesContainsDefaults(): void
    {
        $services = $this->guard->getAllowedServices();
        self::assertContains('App\\AI\\Skills\\Executor\\GenericApiExecutor', $services);
        self::assertContains('App\\AI\\Skills\\Executor\\GenericHttpExecutor', $services);
    }

    public function testIsToolAllowedAlwaysTrue(): void
    {
        self::assertTrue($this->guard->isToolAllowed('any_tool_name'));
        self::assertTrue($this->guard->isToolAllowed('mcp_tool'));
    }

    public function testAddAllowedServiceAddsNew(): void
    {
        $this->guard->addAllowedService('App\\Custom\\Executor');
        self::assertTrue($this->guard->isServiceAllowed('App\\Custom\\Executor'));
    }

    public function testRemoveAllowedServiceRemovesExisting(): void
    {
        $this->guard->addAllowedService('App\\Custom\\Executor');
        self::assertTrue($this->guard->isServiceAllowed('App\\Custom\\Executor'));
        $this->guard->removeAllowedService('App\\Custom\\Executor');
        self::assertFalse($this->guard->isServiceAllowed('App\\Custom\\Executor'));
    }
}
