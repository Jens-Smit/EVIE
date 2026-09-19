<?php

declare(strict_types=1);

namespace App\AI\Agent;

use App\AI\Pipeline\PipelineInterface;
use App\AI\Platform\TenantPlatformContext;

/**
 * Duennen Fassade fuer den Dialog mit dem Orchestrator.
 *
 * ask() delegiert ausschliesslich an die fuenf-Phasen-Pipeline
 * (Goal -> Intent -> Plan -> Capability -> Execution). Der alte
 * reaktive Pfad (JSON-Dispatch, Regex-basierte Sub-Agent-Auswahl,
 * nachtraegliche Intent-Klassifizierung im no_tool_found-Zweig) ist
 * entfernt: Capability Discovery/Generierung findet ausschliesslich
 * in Phase 4 statt und niemals bei Konversation/Information/unclear
 * (Blueprint §5).
 *
 * Vor jedem Aufruf wird der Tenant-Kontext gesetzt, damit der
 * TenantAwarePlatform-Decorator den pro-Tenant API-Key aus dem
 * SecretService aufloest (Luecke 2).
 *
 * @see docs/architecture/orchestrator-pipeline.md
 */
final class OrchestratorDialogService
{
    public function __construct(
        private PipelineInterface $pipeline,
        private TenantPlatformContext $tenantPlatformContext,
    ) {
    }

    /**
     * Sendet eine Nachricht an den Orchestrator und liefert die finale
     * Nutzerantwort aus der Pipeline.
     *
     * Der optionale SystemContext (z.B. persistierter Konversationsverlauf,
     * Luecke 5) wird als SystemMessage vor die User-Nachricht gehaengt, ohne
     * die Phasenlogik der Pipeline zu aendern.
     */
    public function ask(string $userMessage, string $userIdentifier, ?string $systemContext = null): string
    {
        $this->tenantPlatformContext->setUserIdentifier($userIdentifier);
        try {
            $result = $this->pipeline->run($userMessage, $userIdentifier, $systemContext);
        } finally {
            $this->tenantPlatformContext->clear();
        }

        return $result->getContent();
    }
}
