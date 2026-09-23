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

    private function __construct(string $message, string $userIdentifier, ?string $systemContext = null)
    {
        $this->message = $message;
        $this->userIdentifier = $userIdentifier;
        $this->systemContext = $systemContext;
        $this->goal = null;
        $this->intent = null;
        $this->runId = bin2hex(random_bytes(8));
    }

    public static function create(string $message, string $userIdentifier, ?string $systemContext = null): self
    {
        return new self($message, $userIdentifier, $systemContext);
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
        $clone = new self($this->message, $this->userIdentifier);
        $clone->goal = $goal;
        $clone->intent = $this->intent;
        $clone->runId = $this->runId;

        return $clone;
    }

    public function withIntent(Intent $intent): self
    {
        $clone = new self($this->message, $this->userIdentifier);
        $clone->goal = $this->goal;
        $clone->intent = $intent;
        $clone->runId = $this->runId;

        return $clone;
    }
}
