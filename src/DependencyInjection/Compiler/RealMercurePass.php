<?php

namespace App\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * RealMercurePass - stellt im Mercure-E2E-Modus (EVIE_MERCURE_E2E=1) den
 * echten Mercure-Hub wieder her.
 *
 * config/packages/test/services.yaml und der E2EStubPass ersetzen
 * Symfony\Component\Mercure\HubInterface (und das explizite
 * $mercureHub-Argument des StreamingPublisher) durch den
 * NullMercureHub-Stub, damit Tests ohne laufenden Mercure-Hub kompilieren.
 * Der e2e-mercure-CI-Job (und lokale Mercure-Smoke-Runs) sollen jedoch
 * genau die echte Publish-Kette (StreamingPublisher -> mercure.hub.default
 * -> dunglas/mercure v0.24) inklusive Subscriber-Empfang (SSE) pruefen.
 * Ohne diesen Pass wuerden alle Streaming-E2E-Tests stillschweigend
 * gegen den Stub laufen und echte Mercure-Fehler (JWT-Config,
 * Topic-Mismatch, Frontend-Hub-URL) nie entdeckt.
 *
 * Der Pass laeuft erst nach dem Config-Loading (TYPE_BEFORE_REMOVING) und
 * ueberschreibt deshalb beide YAML-Overrides (Alias + explizites Argument).
 * 'mercure.hub.default' ist im debug-Kernel ein Alias auf die
 * TraceableHub-Dekoration; deshalb findDefinition() statt hasDefinition().
 * Ausserhalb des Mercure-E2E-Modus ist der Pass ein No-op, sodass das
 * normale Test- und E2E-Stub-Verhalten unangetastet bleibt.
 */
final class RealMercurePass implements CompilerPassInterface
{
    private const HUB_SERVICE_ID = 'mercure.hub.default';
    private const HUB_INTERFACE_ID = 'Symfony\Component\Mercure\HubInterface';
    private const PUBLISHER_SERVICE_ID = 'App\AI\Streaming\StreamingPublisher';

    public function process(ContainerBuilder $container): void
    {
        if (!$this->isMercureE2e()) {
            return;
        }

        $hubId = $this->resolveHubDefinitionId($container);

        if (null === $hubId) {
            return;
        }

        $container->setAlias(self::HUB_INTERFACE_ID, $hubId)
            ->setPublic(true);

        if ($container->hasDefinition(self::PUBLISHER_SERVICE_ID)) {
            $definition = $container->getDefinition(self::PUBLISHER_SERVICE_ID);
            $arguments = $definition->getArguments();

            if (\array_key_exists('$mercureHub', $arguments)) {
                $definition->replaceArgument('$mercureHub', new Reference($hubId));
            } elseif (\array_key_exists(0, $arguments)) {
                $definition->replaceArgument(0, new Reference($hubId));
            } else {
                $definition->setArgument('$mercureHub', new Reference($hubId));
            }
        }
    }

    /**
     * Löst die Alias-Kette von 'mercure.hub.default' bis zur konkreten
     * Definition auf. Im debug-Kernel ist 'mercure.hub.default' ein Alias
     * auf die TraceableHub-Innere-Definition; ReplaceAliasByActualDefinitionPass
     * (removing-Phase) kann keine Alias-auf-Alias-Ketten inline setzen,
     * deshalb wird hier selbst bis zur Definition aufgeloest.
     */
    private function resolveHubDefinitionId(ContainerBuilder $container): ?string
    {
        $hubId = self::HUB_SERVICE_ID;

        while ($container->hasAlias($hubId)) {
            $hubId = (string) $container->getAlias($hubId);
        }

        return $container->hasDefinition($hubId) ? $hubId : null;
    }

    private function isMercureE2e(): bool
    {
        $value = $_ENV['EVIE_MERCURE_E2E'] ?? (getenv('EVIE_MERCURE_E2E') ?: '');

        return \in_array((string) $value, ['1', 'true', 'yes'], true);
    }
}
