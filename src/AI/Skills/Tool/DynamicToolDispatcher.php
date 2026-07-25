<?php

namespace App\AI\Skills\Tool;

use App\AI\Skills\DynamicSkillRegistry;
use App\AI\Security\SecurityGuard;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[AsTool(
    'dynamic_tool_dispatcher',
    'Führt dynamisch registrierte Tools aus, die in der Datenbank freigegeben sind.'
)]
final class DynamicToolDispatcher
{
    public function __construct(
        private DynamicSkillRegistry $skillRegistry,
        private SecurityGuard $securityGuard,
    ) {
    }

    /**
     * Führt ein dynamisch registriertes Tool aus.
     *
     * @param string $toolName Name des Tools
     * @param array $arguments Argumente für das Tool
     * @return mixed Ergebnis der Tool-Ausführung
     * @throws \RuntimeException Falls das Tool nicht gefunden oder nicht freigegeben ist
     */
    public function __invoke(string $toolName, array $arguments = []): mixed
    {
        $tool = $this->skillRegistry->getTool($toolName);

        if (!$tool) {
            throw new \RuntimeException(sprintf('Tool "%s" nicht gefunden.', $toolName));
        }

        // Sicherheitsprüfung
        if (!$this->securityGuard->validateToolConfiguration($tool->getConfiguration())) {
            throw new \RuntimeException(sprintf('Tool "%s" ist nicht sicher.', $toolName));
        }

        // Tool ausführen
        return $tool->execute($arguments);
    }
}