<?php

declare(strict_types=1);

namespace App\AI\Onboarding;

use App\AI\Platform\TenantPlatformContext;
use Symfony\AI\Agent\Input;
use Symfony\AI\Agent\Memory\Memory;
use Symfony\AI\Agent\Memory\MemoryProviderInterface;

/**
 * Implementierung von MemoryProviderInterface fuer den Kontext des Benutzers.
 *
 * Laedt den Onboarding-Kontext des Tenants aus dem ContextStoreManager und
 * stellt ihn als Memory fuer den Agenten bereit. Der Tenant-Identifier wird
 * aus dem TenantPlatformContext gelesen, den alle EVIE-LLM-Aufrufe (Chat,
 * Strategie-Entwurf, Orchestrator-Pipeline) vor dem Agent-Call setzen - nie
 * aus Request-Daten (P0-5). Die Agent-Option 'user_identifier' bleibt als
 * expliziter Fallback fuer direkte Agent-Calls erhalten.
 */
final class ContextMemoryProvider implements MemoryProviderInterface
{
    public function __construct(
        private readonly ContextStoreManager $contextStore,
        private readonly ?TenantPlatformContext $tenantPlatformContext = null,
    ) {
    }

    /**
     * Laedt den Kontext fuer einen Benutzer und gibt ihn als Memory zurueck.
     *
     * @return array<int, Memory>
     */
    public function load(Input $input): array
    {
        $userIdentifier = $this->resolveUserIdentifier($input);
        $context = $this->contextStore->loadContext($userIdentifier);

        if ($context === []) {
            return [];
        }

        $memories = [];
        if (($context['user_type'] ?? null) !== null) {
            $memories[] = new Memory(sprintf('User Type: %s', $this->stringify($context['user_type'])));
        }
        if (isset($context['preferences'])) {
            $memories[] = new Memory('Preferences: ' . json_encode($context['preferences'], \JSON_UNESCAPED_UNICODE));
        }
        if (isset($context['onboarding_data'])) {
            $memories[] = new Memory(
                'Onboarding-Kontext: '
                . json_encode($context['onboarding_data'], \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)
            );
        }

        return $memories;
    }

    private function resolveUserIdentifier(Input $input): string
    {
        $option = $input->getOptions()['user_identifier'] ?? null;
        if (\is_string($option) && $option !== '') {
            return $option;
        }

        $tenant = $this->tenantPlatformContext?->getUserIdentifier();
        if (\is_string($tenant) && $tenant !== '') {
            return $tenant;
        }

        return 'unknown';
    }

    private function stringify(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_array($value)) {
            return (string) ($value[0] ?? '');
        }

        return '';
    }
}
