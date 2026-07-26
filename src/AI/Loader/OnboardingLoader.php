<?php

namespace App\AI\Loader;

use Symfony\AI\Store\Document\TextDocument;
use Symfony\AI\Store\Document\Loader\LoaderInterface;

/**
 * Lädt Onboarding-Dokumente für den Indexer.
 */
final class OnboardingLoader implements LoaderInterface
{
    public function load(?string $source = null, array $options = []): iterable
    {
        yield new TextDocument(
            id: 'welcome',
            text: 'Willkommen bei EVIE.'
        );

        yield new TextDocument(
            id: 'company',
            text: 'Der Benutzer kann sein Unternehmen anlegen.'
        );
    }
}