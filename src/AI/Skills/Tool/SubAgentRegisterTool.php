<?php

declare(strict_types=1);

namespace App\AI\Skills\Tool;

use App\Entity\SubAgentDefinition;
use App\Repository\SubAgentDefinitionRepository;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

/**
 * Statisches Tool zum Registieren neuer Sub-Agent-Definitionen in der Datenbank.
 *
 * Ermöglicht dem Orchestrator, während eines Setup-Tasks neue Sub-Agenten
 * (z.B. Verwaltung, Support, Vertrieb, Marketing, Controlling) autonom
 * anzulegen. Das Tool ist nativ als #[AsTool] registriert (kein
 * Konstruktor-Injection für Tools, Blueprint §4.D) und legt eine
 * SubAgentDefinition an, die von der SubAgentFactory geladen wird.
 *
 * HITL: Die Registrierung eines Sub-Agenten ist eine strukturelle Aktion;
 * das Tool selbst führt keine externen Aktionen aus. Die SubAgentDefinition
 * ist isActive=true, damit der Sub-Agent nach Registrierung verfügbar ist.
 */
#[AsTool(
    name: 'sub_agent_register',
    description: 'Registriert einen neuen Sub-Agenten in der Datenbank. Parameter: name (Sub-Agent-Name, eindeutig), description (Rolle/Aufgabe), role (Rollenschlüssel für Prompt, z.B. marketing_manager), model (optional, Default mistral-large-latest).'
)]
final class SubAgentRegisterTool
{
    public function __construct(
        private SubAgentDefinitionRepository $subAgentDefinitionRepository,
    ) {
    }

    public function __invoke(array $parameters = []): array
    {
        $name = $parameters['name'] ?? '';
        $description = $parameters['description'] ?? '';
        $role = $parameters['role'] ?? $name;
        $model = $parameters['model'] ?? 'mistral-large-latest';

        if ($name === '' || $description === '') {
            throw new \RuntimeException('Parameter name und description sind erforderlich.');
        }

        $existing = $this->subAgentDefinitionRepository->findOneByName($name);
        if ($existing !== null) {
            return [
                'status' => 'exists',
                'sub_agent_name' => $existing->getName(),
                'message' => sprintf('Sub-Agent "%s" existiert bereits (ID: %s).', $name, $existing->getId() ?? 'unbekannt'),
            ];
        }

        $definition = new SubAgentDefinition();
        $definition->setName($name);
        $definition->setDescription($description);
        // P1: class_name null — Erzeugung via SubAgentFactory-Konfiguration
        // statt nicht existierender DI-Service-ID (dev-tail-Log-Fehler).
        $definition->setClassName(null);
        $definition->setConfiguration([
            'model' => $model,
            'role' => $role,
        ]);
        $definition->setIsActive(true);
        $definition->setCreatedAt(new \DateTimeImmutable());

        $this->subAgentDefinitionRepository->save($definition, true);

        return [
            'status' => 'success',
            'sub_agent_name' => $definition->getName(),
            'sub_agent_id' => $definition->getId() !== null ? $definition->getId()->toRfc4122() : null,
            'message' => sprintf('Sub-Agent "%s" wurde registriert (Rolle: %s, Modell: %s).', $name, $role, $model),
        ];
    }
}
