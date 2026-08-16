<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Security;

use App\AI\Security\OutboundRequestPolicy;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * OutboundRequestPolicy-Tests (P0-3).
 *
 * Die Policy ist die Defense-in-Depth-Pruefung hinter SecurityGuard::isUrlSafe()
 * und loest Hostnamen per DNS auf, sodass eine Domain, die auf eine private IP
 * zeigt (z. B. 169.254.169.254 fuer den AWS-Metadata-Endpunkt), geblockt wird.
 *
 * Da echte DNS-Aufloesung im Unit-Test nicht deterministisch ist, wird hier
 * direkt gegen eine IP getestet, die isPrivateNetwork() bzw. isIpAllowed()
 * trifft. Der Domains-zu-private-IP-Pfad wird ueber SecurityGuardTest
 * (mit Policy-Injection) abgedeckt.
 */
final class OutboundRequestPolicyTest extends TestCase
{
    private OutboundRequestPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new OutboundRequestPolicy(new NullLogger(), [
            'allow_private_networks' => false,
            'allow_redirects' => false,
            'max_redirects' => 0,
        ]);
    }

    public function testBlocksLinkLocalMetadataIp(): void
    {
        // 169.254.169.254 = AWS/GCP-Instanzmetadaten-Endpunkt.
        self::assertFalse($this->policy->isUrlAllowed('http://169.254.169.254/latest/meta-data/'));
    }

    public function testBlocksLoopback(): void
    {
        self::assertFalse($this->policy->isUrlAllowed('http://127.0.0.1/admin'));
        self::assertFalse($this->policy->isUrlAllowed('http://localhost/admin'));
    }

    public function testBlocksPrivateRanges(): void
    {
        self::assertFalse($this->policy->isUrlAllowed('http://192.168.1.1/secret'));
        self::assertFalse($this->policy->isUrlAllowed('http://10.0.0.1/internal'));
        self::assertFalse($this->policy->isUrlAllowed('http://172.16.0.1/x'));
    }

    public function testBlocksIpv6LoopbackAndLinkLocal(): void
    {
        self::assertFalse($this->policy->isUrlAllowed('http://[::1]/admin'));
        self::assertFalse($this->policy->isUrlAllowed('http://[fe80::1]/admin'));
    }

    public function testBlocksNonHttpSchemes(): void
    {
        self::assertFalse($this->policy->isUrlAllowed('file:///etc/passwd'));
        self::assertFalse($this->policy->isUrlAllowed('gopher://127.0.0.1/'));
    }

    public function testBlocksDirectoryTraversalInPath(): void
    {
        self::assertFalse($this->policy->isUrlAllowed('https://example.com/../../etc/passwd'));
        self::assertFalse($this->policy->isUrlAllowed('https://example.com/%2e%2e/etc/passwd'));
    }

    public function testBlocksNonStandardPort(): void
    {
        // Nur 80, 443, 8080, 8443 sind erlaubt.
        self::assertFalse($this->policy->isUrlAllowed('https://example.com:8444/'));
    }

    public function testAllowsPublicUrlOnStandardPort(): void
    {
        self::assertTrue($this->policy->isUrlAllowed('https://example.com/data'));
        self::assertTrue($this->policy->isUrlAllowed('http://example.com:8080/data'));
    }

    public function testRejectsMalformedUrl(): void
    {
        self::assertFalse($this->policy->isUrlAllowed(':::not-a-url'));
    }

    /**
     * Komprimierte und nicht-kanonische IPv6-Formen muessen als privat
     * erkannt werden (Audit: "IPv6-Normalisierung unvollstaendig").
     */
    public function testBlocksCompressedIpv6Loopback(): void
    {
        self::assertFalse($this->policy->isUrlAllowed('http://[::1]/admin'));
    }

    public function testBlocksUnspecifiedIpv6(): void
    {
        // :: = unspecified address (0.0.0.0 equivalent).
        self::assertFalse($this->policy->isUrlAllowed('http://[::]/admin'));
    }

    public function testBlocksCompressedUniqueLocalIpv6(): void
    {
        // fd00::1 = Unique Local Address (ULA), komprimierte Form.
        self::assertFalse($this->policy->isUrlAllowed('http://[fd00::1]/admin'));
        // fc00::1 = ULA, andere Haelfte.
        self::assertFalse($this->policy->isUrlAllowed('http://[fc00::1]/admin'));
    }

    public function testBlocksCompressedLinkLocalIpv6(): void
    {
        // fe80::1 = Link-Local, komprimierte Form.
        self::assertFalse($this->policy->isUrlAllowed('http://[fe80::1]/admin'));
    }

    public function testBlocksIpv4MappedIpv6PointingToPrivate(): void
    {
        // ::ffff:127.0.0.1 = IPv4-mapped IPv6, die auf Loopback zeigt.
        self::assertFalse($this->policy->isUrlAllowed('http://[::ffff:127.0.0.1]/admin'));
        // ::ffff:169.254.169.254 = AWS-Metadaten-Endpunkt als IPv4-mapped IPv6.
        self::assertFalse($this->policy->isUrlAllowed('http://[::ffff:169.254.169.254]/latest/meta-data/'));
    }

    public function testAllowsPublicIpv6(): void
    {
        // 2606:4700::1 = Cloudflare public DNS, keine private Range.
        self::assertTrue($this->policy->isUrlAllowed('http://[2606:4700::1]/data'));
    }

    public function testResolveAllowedIpReturnsNullForLoopback(): void
    {
        self::assertNull($this->policy->resolveAllowedIp('http://127.0.0.1/admin'));
        self::assertNull($this->policy->resolveAllowedIp('http://[::1]/admin'));
    }

    public function testResolveAllowedIpReturnsIpForDirectIp(): void
    {
        // Eine zulaessige (public) IP wird als Pinning-Target zurueckgegeben.
        self::assertSame('8.8.8.8', $this->policy->resolveAllowedIp('http://8.8.8.8/dns'));
    }

    public function testResolveAllowedIpReturnsNullForMalformedUrl(): void
    {
        self::assertNull($this->policy->resolveAllowedIp(':::not-a-url'));
    }
}
