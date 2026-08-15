<?php

declare(strict_types=1);

namespace App\DependencyInjection\Compiler;

use App\Tests\Stub\NullMercureHub;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Registers test stubs for external services (Mercure hub, streaming publisher)
 * when the E2E_TESTING flag is set.
 *
 * This allows the E2E test suite to boot the dev/prod container configuration
 * — exercising the real security, form, routing and controller stack — without
 * requiring a running Mercure hub or other live infrastructure.
 *
 * The pass is a no-op in normal operation (no E2E_TESTING env var), so the
 * production/dev service wiring stays untouched.
 */
final class E2EStubPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$this->isE2ETesting($container)) {
            return;
        }

        // Register the NullMercureHub as the HubInterface implementation so the
        // dev/prod container compiles and StreamingPublisher can be wired without
        // a real Mercure server.
        $container->register(NullMercureHub::class, NullMercureHub::class)
            ->setPublic(false);

        $container->setAlias('Symfony\\Component\\Mercure\\HubInterface', NullMercureHub::class)
            ->setPublic(false);

        // StreamingPublisher takes a HubInterface; with the alias above it will
        // receive the stub automatically.
    }

    private function isE2ETesting(ContainerBuilder $container): bool
    {
        $value = $container->hasParameter('kernel.e2e_testing')
            ? $container->getParameter('kernel.e2e_testing')
            : (getenv('E2E_TESTING') ?: ($_ENV['E2E_TESTING'] ?? ''));

        return in_array((string) $value, ['1', 'true', 'yes'], true);
    }
}
