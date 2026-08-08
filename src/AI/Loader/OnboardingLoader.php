<?php

namespace App\AI\Loader;

use Symfony\AI\Store\Document\TextDocument;
use Symfony\AI\Store\Document\LoaderInterface;

/**
 * Lädt Onboarding-Dokumente für den Indexer.
 */
final class OnboardingLoader implements LoaderInterface
{
    public function load(?string $source = null, array $options = []): iterable
    {
        yield new TextDocument(
            id: 'welcome',
            content: 'Willkommen bei EVIE.'
        );

        yield new TextDocument(
            id: 'company',
            content: 'Der Benutzer kann sein Unternehmen anlegen.'
        );
    }
}