<?php

declare(strict_types=1);

// tests/Unit/AI/Security/OutboundAllowlistProviderTest.php

namespace App\Tests\Unit\AI\Security;

use App\AI\Security\OutboundAllowlistProvider;
use App\AI\Security\OutboundRequestPolicy;
use App\Entity\OutboundAllowlistEntry;
use App\Repository\OutboundAllowlistEntryRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit-Tests fuer die Frontend-Freigabe ausgehender HTTP-Ziele
 * (OutboundAllowlistProvider + OutboundRequestPolicy-Freigabemodus).
 *
 * Kern-Invarianten:
 *  - Ohne aktive Freigaben bleibt der Legacy-Default aktiv (oeffentliche
 *    Hosts erlaubt, interne blockiert).
 *  - Mit mindestens einer aktiven Freigabe sind NUR freigegebene Hosts
 *    erlaubt (Security by Default, Blueprint §4.D).
 *  - Eine Freigabe hebt die SSRF-Blockliste nicht auf: interne Zieladressen
 *    bleiben blockiert, selbst wenn der Host per Freigabe matched.
 */
final class OutboundAllowlistProviderTest extends TestCase
{
    private OutboundAllowlistEntryRepository&MockObject $repository;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(OutboundAllowlistEntryRepository::class);
    }

    private function createProvider(): OutboundAllowlistProvider
    {
        return new OutboundAllowlistProvider($this->repository);
    }

    private function createEntry(string $host, string $patternType = OutboundAllowlistEntry::TYPE_SUFFIX): OutboundAllowlistEntry
    {
        $entry = new OutboundAllowlistEntry();
        $entry->setHostPattern($host);
        $entry->setPatternType($patternType);

        return $entry;
    }

    public function testToFnmatchPatternSuffixIncludesSubdomains(): void
    {
        $provider = $this->createProvider();
        self::assertSame('*.api.tavily.com', $provider->toFnmatchPattern('api.tavily.com', 'suffix'));
    }

    public function testToFnmatchPatternExactStaysVerbatim(): void
    {
        $provider = $this->createProvider();
        self::assertSame('api.tavily.com', $provider->toFnmatchPattern('api.tavily.com', 'exact'));
    }

    public function testGetActiveHostPatternsReturnsPatternsForActiveEntries(): void
    {
        $this->repository
            ->method('findAllActive')
            ->willReturn([
                $this->createEntry('api.tavily.com', 'exact'),
                $this->createEntry('mistral.ai', 'suffix'),
            ]);

        $provider = $this->createProvider();
        // Suffix-Freigaben erzeugen zwei Patterns: Subdomain-Wildcard und
        // Bare-Host (mistral.ai selbst + jede Subdomain, siehe Provider-Doku).
        self::assertSame(
            ['api.tavily.com', '*.mistral.ai', 'mistral.ai'],
            $provider->getActiveHostPatterns()
        );
    }

    public function testRegisterEnablesExplicitApprovalMode(): void
    {
        $this->repository
            ->method('findAllActive')
            ->willReturn([$this->createEntry('api.tavily.com', 'exact')]);

        $policy = new OutboundRequestPolicy(new NullLogger(), [
            'allow_private_networks' => false,
            'allow_redirects' => false,
            'max_redirects' => 0,
        ]);

        $this->createProvider()->register($policy);

        self::assertTrue($policy->isUrlAllowed('https://api.tavily.com/search'));
        self::assertFalse($policy->isUrlAllowed('https://other.example.com/data'));
    }

    public function testApprovalDoesNotBypassSsrfBlocklist(): void
    {
        // Freigabe fuer einen Host, der auf eine private IP zeigt: Die
        // Blockliste der Policy bleibt vor der Freigabe aktiv.
        $this->repository
            ->method('findAllActive')
            ->willReturn([$this->createEntry('localhost', 'exact')]);

        $policy = new OutboundRequestPolicy(new NullLogger(), [
            'allow_private_networks' => false,
            'allow_redirects' => false,
            'max_redirects' => 0,
        ]);

        $this->createProvider()->register($policy);

        self::assertFalse($policy->isUrlAllowed('http://localhost/admin'));
        self::assertFalse($policy->isUrlAllowed('http://127.0.0.1/admin'));
    }

    public function testWithoutActiveEntriesLegacyDefaultStaysActive(): void
    {
        $this->repository
            ->method('findAllActive')
            ->willReturn([]);

        $policy = new OutboundRequestPolicy(new NullLogger(), [
            'allow_private_networks' => false,
            'allow_redirects' => false,
            'max_redirects' => 0,
        ]);

        $this->createProvider()->register($policy);

        self::assertTrue($policy->isUrlAllowed('https://api.tavily.com/search'));
        self::assertTrue($policy->isUrlAllowed('https://example.com/data'));
        self::assertFalse($policy->isUrlAllowed('http://127.0.0.1/admin'));
    }

    public function testLoaderFailureKeepsPolicyDefaults(): void
    {
        $this->repository
            ->method('findAllActive')
            ->willThrowException(new \RuntimeException('DB nicht verfuegbar'));

        $policy = new OutboundRequestPolicy(new NullLogger(), [
            'allow_private_networks' => false,
            'allow_redirects' => false,
            'max_redirects' => 0,
        ]);

        $this->createProvider()->register($policy);

        // Fehler beim Laden darf keine Freigabe erzwingen: Default-Pfad.
        self::assertTrue($policy->isUrlAllowed('https://example.com/data'));
        self::assertFalse($policy->isUrlAllowed('http://169.254.169.254/latest/meta-data/'));
    }

    public function testApplyNowLoadsPatternsImmediately(): void
    {
        $this->repository
            ->method('findAllActive')
            ->willReturn([$this->createEntry('mistral.ai', 'suffix')]);

        $policy = new OutboundRequestPolicy(new NullLogger(), [
            'allow_private_networks' => false,
            'allow_redirects' => false,
            'max_redirects' => 0,
        ]);

        $this->createProvider()->applyNow($policy);

        self::assertTrue($policy->isUrlAllowed('https://api.mistral.ai/v1/models'));
        self::assertTrue($policy->isUrlAllowed('https://mistral.ai/v1/models'));
        self::assertFalse($policy->isUrlAllowed('https://api.tavily.com/search'));
    }

    public function testWildcardApprovalMatchesFnmatch(): void
    {
        $this->repository
            ->method('findAllActive')
            ->willReturn([$this->createEntry('api.*.example.com', 'wildcard')]);

        $policy = new OutboundRequestPolicy(new NullLogger(), [
            'allow_private_networks' => false,
            'allow_redirects' => false,
            'max_redirects' => 0,
        ]);

        $this->createProvider()->register($policy);

        self::assertTrue($policy->isUrlAllowed('https://api.eu.example.com/data'));
        self::assertFalse($policy->isUrlAllowed('https://api.example.com/data'));
    }
}
