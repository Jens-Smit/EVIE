<?php

namespace App\AI\Skills;

use App\Entity\ToolDefinition;
use App\AI\Skills\Tool\DynamicTool;

/**
 * Ergebnis der Tool-Registrierung
 */
class ToolRegistrationResult
{
    private bool $success;
    private ?DynamicTool $tool;
    private ?ToolDefinition $definition;
    private ?string $errorMessage;
    private ?array $errorDetails;

    public function __construct(
        bool $success,
        ?DynamicTool $tool = null,
        ?ToolDefinition $definition = null,
        ?string $errorMessage = null,
        ?array $errorDetails = null
    ) {
        $this->success = $success;
        $this->tool = $tool;
        $this->definition = $definition;
        $this->errorMessage = $errorMessage;
        $this->errorDetails = $errorDetails;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getTool(): ?DynamicTool
    {
        return $this->tool;
    }

    public function getDefinition(): ?ToolDefinition
    {
        return $this->definition;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function getErrorDetails(): ?array
    {
        return $this->errorDetails;
    }

    public static function success(DynamicTool $tool, ToolDefinition $definition): self
    {
        return new self(true, $tool, $definition);
    }

    public static function failure(string $errorMessage, array $errorDetails = []): self
    {
        return new self(false, null, null, $errorMessage, $errorDetails);
    }
}
