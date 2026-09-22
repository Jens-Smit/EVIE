<?php

declare(strict_types=1);

namespace App\AI\Security;

use App\Repository\OutboundAllowlistEntryRepository;

/**
 * Speist die im Frontend freigegebenen Outbound-Ziele (OutboundAllowlistEntry)
 * in die OutboundRequestPolicy ein (Blueprint §4.D/§5: explizite Freigabe).
 *
 * Die Policy laedt die Freigaben lazy beim ersten URL-Check, damit CLI/Cache-
 * Warmup ohne Datenbank nicht scheitern. Ein Fehler beim Laden laesst die
 * Policy-Defaults aktiv (keine stillschweigende Freigabe).
 */
final class OutboundAllowlistProvider
{
    public function __construct(
        private readonly OutboundAllowlistEntryRepository $repository,
    ) {
    }

    /**
     * Verdrahtet die DB-Freigaben mit der Policy.
     */
    public function register(OutboundRequestPolicy $policy): void
    {
        $policy->setApprovedPatternLoader(
            fn (): array => $this->getActiveHostPatterns(),
        );
    }

    /**
     * Aktiviert die Freigaben sofort (fuer Tests und CLI-Kontexte).
     */
    public function applyNow(OutboundRequestPolicy $policy): void
    {
        $policy->applyApprovedHostPatterns($this->getActiveHostPatterns());
    }

    /**
     * fnmatch-Patterns aller aktiven Freigaben.
     *
     * @return list<string>
     */
    public function getActiveHostPatterns(): array
    {
        $patterns = [];
        foreach ($this->repository->findAllActive() as $entry) {
            $patterns[] = $this->toFnmatchPattern(
                $entry->getHostPattern(),
                $entry->getPatternType(),
            );
        }

        return $patterns;
    }

    /**
     * Uebersetzt einen Freigabe-Eintrag in ein fnmatch-Pattern.
     */
    public function toFnmatchPattern(string $hostPattern, string $patternType): string
    {
        $host = strtolower(trim($hostPattern));

        return match ($patternType) {
            // Exakt: nur dieser Host.
            'exact' => $host,
            // Wildcard: Pattern enthaelt bereits fnmatch-Syntax.
            'wildcard' => $host,
            // Suffix (Default): Host selbst oder jede Subdomain
            // ("mistral.ai" erlaubt "mistral.ai" und "api.mistral.ai").
            default => '*.' . $host,
        };
    }
}
