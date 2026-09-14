<?php

declare(strict_types=1);

namespace App\AI\Pipeline\Execution;

/**
 * Value-Object: Ergebnis eines gesamten Pipeline-Laufs.
 *
 * Kapselt die finale Nutzerantwort zusammen mit dem Ergebnis-Typ
 * (dialog, clarify, awaitingApproval, executed). Reine Datenstruktur;
 * die eigentliche Ausführung erfolgt im ExecutionCoordinator ueber die
 * native Agent-Loop.
 *
 * @see docs/architecture/orchestrator-pipeline.md Phase 5
 */
final class PipelineResult
{
    public const TYPE_DIALOG = 'dialog';
    public const TYPE_CLARIFY = 'clarify';
    public const TYPE_AWAITING_APPROVAL = 'awaiting_approval';
    public const TYPE_EXECUTED = 'executed';
    public const TYPE_ERROR = 'error';

    private string $type;
    private string $content;
    private ?int $toolDefinitionId;

    public function __construct(string $type, string $content, ?int $toolDefinitionId = null)
    {
        $this->type = $type;
        $this->content = $content;
        $this->toolDefinitionId = $toolDefinitionId;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getToolDefinitionId(): ?int
    {
        return $this->toolDefinitionId;
    }
}
