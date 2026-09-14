<?php

declare(strict_types=1);

namespace App\AI\Agent;

use App\AI\Pipeline\PipelineInterface;

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
 * @see docs/architecture/orchestrator-pipeline.md
 */
final class OrchestratorDialogService
{
    public function __construct(
        private PipelineInterface $pipeline,
    ) {
    }

    /**
     * Sendet eine Nachricht an den Orchestrator und liefert die finale
     * Nutzerantwort aus der Pipeline.
     */
    public function ask(string $userMessage, string $userIdentifier): string
    {
        $result = $this->pipeline->run($userMessage, $userIdentifier);

        return $result->getContent();
    }
}
