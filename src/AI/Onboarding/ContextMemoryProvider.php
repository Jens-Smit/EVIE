<?php

namespace App\AI\Onboarding;

use Symfony\AI\Agent\Input;
use Symfony\AI\Agent\Memory\Memory;
use Symfony\AI\Agent\Memory\MemoryProviderInterface;

/**
 * Implementierung von MemoryProviderInterface für den Kontext des Benutzers.
 * Lädt den Benutzerkontext aus dem ContextStoreManager und stellt ihn als Memory für den Agenten bereit.
 */
final class ContextMemoryProvider implements MemoryProviderInterface
{
    public function __construct(private ContextStoreManager $contextStore) {}

    /**
     * Lädt den Kontext für einen Benutzer und gibt ihn als Memory zurück.
     */
    public function load(Input $input): array
    {
        $userIdentifier = $input->options['user_identifier'] ?? 'unknown';
        $context = $this->contextStore->loadContext($userIdentifier);

        return [
            new Memory(sprintf('User Type: %s', $context['user_type'] ?? 'unknown')),
            new Memory('Preferences: ' . json_encode($context['preferences'] ?? [], JSON_UNESCAPED_UNICODE)),
        ];
    }
}