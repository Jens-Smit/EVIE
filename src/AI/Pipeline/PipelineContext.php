<?php

declare(strict_types=1);

namespace App\AI\Pipeline;

use App\AI\Pipeline\Goal\Goal;
use App\AI\Pipeline\Intent\Intent;

/**
 * Value-Object: Kontext, der alle Phasen eines Pipeline-Laufs begleitet.
 *
 * Wird in Phase 1 erzeugt und von jeder Phase mit ihren Ergebnissen
 * angereichert (immutable via with*()-Methoden). Traegt die User-Nachricht,
 * den Identifier, das Goal (Phase 1) und den Intent (Phase 2). Der Plan
 * (Phase 3) wird bewusst nicht im Kontext gehalten, da er nur fuer Phase 4
 * und 5 relevant ist und dort direkt uebergeben wird.
 *
 * @see docs/architecture/orchestrator-pipeline.md
 */
final class PipelineContext
{
    private string $message;
    private string $userIdentifier;
    private ?string $systemContext;
    private ?Goal $goal;
    private ?Intent $intent;
    private string $runId;
    private bool $progressEnabled;

    private function __construct(string $message, string $userIdentifier, ?string $systemContext = null, ?string $runId = null)
    {
        $this->message = $message;
        $this->userIdentifier = $userIdentifier;
        $this->systemContext = $systemContext;
        $this->goal = null;
        $this->intent = null;
        $this->runId = $runId ?? bin2hex(random_bytes(8));
        $this->progressEnabled = $runId !== null;
    }

    public static function create(string $message, string $userIdentifier, ?string $systemContext = null, ?string $runId = null): self
    {
        return new self($message, $userIdentifier, $systemContext, $runId);
    }

    /**
     * P2 Observability: Eindeutige run_id des Pipeline-Laufs. Wird in
     * Phase 1 erzeugt und von allen Phasen-Logs getragen, damit ein
     * kompletter Workflow (Goal -> Intent -> Plan -> Capability ->
     * Execution) im Log als zusammenhaengender Trace lesbar ist.
     */
    public function getRunId(): string
    {
        return $this->runId;
    }

    /**
     * Live-Progress im Dialog: Nur wenn der Client eine Session-ID
     * uebergeben hat (und damit ein Mercure-Topic abonniert hat), duerfen
     * Progress-Events publiziert werden. Interne Laeufe ohne Session-ID
     * (Scheduler, RunAgentGoalHandler, StrategyManager) bleiben stumm.
     */
    public function isProgressEnabled(): bool
    {
        return $this->progressEnabled;
    }

    /**
     * Persistenter System-Kontext (z.B. Konversationsverlauf aus der
     * AgentHistory, Luecke 5). Wird in Phase 5 als SystemMessage in den
     * Prompt eingebaut, ohne die Phasenlogik zu aendern.
     */
    public function getSystemContext(): ?string
    {
        return $this->systemContext;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getUserIdentifier(): string
    {
        return $this->userIdentifier;
    }

    public function getGoal(): ?Goal
    {
        return $this->goal;
    }

    public function getIntent(): ?Intent
    {
        return $this->intent;
    }

    public function withGoal(Goal $goal): self
    {
        $clone = new self($this->message, $this->userIdentifier, $this->systemContext, $this->runId);
        $clone->goal = $goal;
        $clone->intent = $this->intent;
        $clone->progressEnabled = $this->progressEnabled;

        return $clone;
    }

    public function withIntent(Intent $intent): self
    {
        $clone = new self($this->message, $this->userIdentifier, $this->systemContext, $this->runId);
        $clone->goal = $this->goal;
        $clone->intent = $intent;
        $clone->progressEnabled = $this->progressEnabled;

        return $clone;
    }
}
