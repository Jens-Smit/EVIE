<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Onboarding;

use App\AI\Onboarding\ContextMemoryProvider;
use App\AI\Onboarding\ContextStoreManager;
use App\AI\Platform\TenantPlatformContext;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Input;
use Symfony\AI\Platform\Message\MessageBag;

/**
 * Unit-Tests fuer den ContextMemoryProvider (Agent-Memory mit Tenant-Bezug).
 *
 * Der Provider ist die Memory-Quelle aller EVIE-Agents (Orchestrator,
 * Onboarding, Tool-Generator). Er muss den Tenant-Identifier aus dem
 * TenantPlatformContext aufloesen (gesetzt von withTenantContext() in
 * OnboardingFlowManager/OrchestratorDialogService) und den Onboarding-
 * Kontext als Memory liefern, damit der Chat den Kontext ueber Runden
 * behaelt.
 */
final class ContextMemoryProviderTest extends TestCase
{
    public function testLoadReturnsEmptyMemoryWithoutContext(): void
    {
        $contextStore = $this->createContextStore([]);
        $provider = new ContextMemoryProvider($contextStore);

        $input = new Input('mistral-small-latest', new MessageBag());

        self::assertSame([], $provider->load($input));
    }

    public function testLoadReadsTenantFromTenantPlatformContext(): void
    {
        $tenantContext = new TenantPlatformContext();
        $tenantContext->setUserIdentifier('tenant-42');

        // Der Kontext darf NUR fuer tenant-42 geliefert werden: beweist,
        // dass der TenantPlatformContext-Identifier statt 'unknown' genutzt
        // wird (Kontext-Behalt im Chat).
        $contextStore = $this->createMock(ContextStoreManager::class);
        $contextStore->expects(self::once())
            ->method('loadContext')
            ->with('tenant-42')
            ->willReturn(['onboarding_data' => ['mission_statement' => 'Vertrieb unterstuetzen']]);
        $provider = new ContextMemoryProvider($contextStore, $tenantContext);

        $input = new Input('mistral-small-latest', new MessageBag());
        $memories = $provider->load($input);

        self::assertNotEmpty($memories);
        self::assertStringContainsString(
            'Vertrieb unterstuetzen',
            implode("\n", array_map(static fn ($m) => $m->getContent(), $memories))
        );
    }

    public function testLoadPrefersExplicitUserIdentifierOption(): void
    {
        $tenantContext = new TenantPlatformContext();
        $tenantContext->setUserIdentifier('tenant-from-context');

        $contextStore = $this->createMock(ContextStoreManager::class);
        $contextStore->expects(self::once())
            ->method('loadContext')
            ->with('tenant-explicit')
            ->willReturn(['user_type' => 'business']);
        $provider = new ContextMemoryProvider($contextStore, $tenantContext);

        $input = new Input('mistral-small-latest', new MessageBag(), ['user_identifier' => 'tenant-explicit']);
        $memories = $provider->load($input);

        self::assertNotEmpty($memories);
        self::assertStringContainsString('business', $memories[0]->getContent());
    }

    public function testLoadFallsBackToUnknownWithoutAnyTenantContext(): void
    {
        $contextStore = $this->createMock(ContextStoreManager::class);
        $contextStore->expects(self::once())
            ->method('loadContext')
            ->with('unknown')
            ->willReturn([]);
        $provider = new ContextMemoryProvider($contextStore);

        $input = new Input('mistral-small-latest', new MessageBag());

        self::assertSame([], $provider->load($input));
    }

    public function testLoadSerializesOnboardingDataAsMemory(): void
    {
        $contextStore = $this->createContextStore([
            'onboarding_data' => [
                'llm_provider' => 'mistral',
                'strategy_confirmed' => true,
            ],
            'preferences' => ['language' => 'de'],
        ]);
        $provider = new ContextMemoryProvider($contextStore);

        $input = new Input('mistral-small-latest', new MessageBag(), ['user_identifier' => 'tenant-1']);
        $memories = $provider->load($input);

        $joined = implode("\n", array_map(static fn ($m) => $m->getContent(), $memories));
        self::assertStringContainsString('Preferences', $joined);
        self::assertStringContainsString('Onboarding-Kontext', $joined);
        self::assertStringContainsString('strategy_confirmed', $joined);
    }

    private function createContextStore(array $context): ContextStoreManager&MockObject
    {
        $contextStore = $this->createMock(ContextStoreManager::class);
        $contextStore->method('loadContext')->willReturn($context);

        return $contextStore;
    }
}
