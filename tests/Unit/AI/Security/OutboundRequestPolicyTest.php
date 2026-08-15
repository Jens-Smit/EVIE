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
}
