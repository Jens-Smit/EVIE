<?php

declare(strict_types=1);

namespace App\AI\Skills\Tool;

/**
 * Ergebnis einer dynamischen Tool-Ausführung (Blueprint §4.F).
 *
 * Kapselt Erfolg/Fehler, Tool-Name, Ergebnis-Daten und Fehlermeldung.
 */
final class ToolExecutionResult
{
    public function __construct(
        private readonly string $toolName,
        private readonly bool $success,
        private readonly ?string $errorMessage = null,
        private readonly mixed $result = null,
    ) {
    }

    public function getToolName(): string
    {
        return $this->toolName;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function getResult(): mixed
    {
        return $this->result;
    }
}
