<?php

declare(strict_types=1);

namespace App\DependencyInjection\Compiler;

use App\AI\Skills\DynamicToolbox;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Registriert die native DynamicToolbox als Decorator der
 * "ai.toolbox.orchestrator", sofern dieser Service vom AI Bundle erstellt
 * wurde (Blueprint §4.B).
 *
 * Das AI Bundle erzeugt die Toolbox-Definition nur, wenn für den Agenten
 * Tools aktiviert sind (prod/dev). In der test/e2e-Konfiguration ist
 * tools=false, dort existiert ai.toolbox.orchestrator nicht — der
 * DynamicToolbox-Service wird dann aus dem Container entfernt, damit das
 * Kompilat auch ohne aktivierte Tools funktioniert.
 */
final class RegisterDynamicToolboxDecoratorPass implements CompilerPassInterface
{
    public const TOOLBOX_SERVICE_ID = 'ai.toolbox.orchestrator';
    public const DECORATOR_SERVICE_ID = 'App\\AI\\Skills\\DynamicToolbox';

    public function process(ContainerBuilder $container): void
    {
        // Ohne aktivierte Orchestrator-Toolbox hat die DynamicToolbox keine
        // innere Toolbox zum Dekorieren — Service entfernen.
        if (!$container->hasDefinition(self::TOOLBOX_SERVICE_ID)) {
            $container->removeDefinition(self::DECORATOR_SERVICE_ID);

            return;
        }

        $decorator = $container->hasDefinition(self::DECORATOR_SERVICE_ID)
            ? $container->getDefinition(self::DECORATOR_SERVICE_ID)
            : new Definition(DynamicToolbox::class);
        $decorator->setArguments([
            new Reference(self::DECORATOR_SERVICE_ID.'.inner'),
            new Reference('App\\Repository\\ToolDefinitionRepository'),
        ]);
        // Höhere Priorität als die FaultTolerantToolbox (-1024), damit die
        // DynamicToolbox die äußerste Schicht bildet (Tools mergen vor der
        // Fehlerbehandlung). Symfony baut die Decorator-Kette entsprechend.
        $decorator->setDecoratedService(self::TOOLBOX_SERVICE_ID, null, 1024);
        $container->setDefinition(self::DECORATOR_SERVICE_ID, $decorator);
    }
}
