<?php

namespace App\AI\Workflow;

/**
 * Ergebnis einer Execution
 */
class ExecutionResult
{
    private bool $success;
    private ?array $result;
    private ?string $error;
    private string $originalRequest;

    public function __construct(
        bool $success,
        ?array $result,
        ?string $error,
        string $originalRequest
    ) {
        $this->success = $success;
        $this->result = $result;
        $this->error = $error;
        $this->originalRequest = $originalRequest;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getResult(): ?array
    {
        return $this->result;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getOriginalRequest(): string
    {
        return $this->originalRequest;
    }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'result' => $this->result,
            'error' => $this->error,
            'original_request' => $this->originalRequest
        ];
    }
}
