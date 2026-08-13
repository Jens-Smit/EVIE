<?php

namespace AppAISkills;

use AppEntityToolDefinition;

class ToolRegistrationException extends Exception
{
    private ?ToolDefinition $definition;
    private ?array $context;

    public function __construct(
        string $message,
        ?ToolDefinition $definition = null,
        ?array $context = null,
        int $code = 0,
        Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->definition = $definition;
        $this->context = $context;
    }

    public function getDefinition(): ?ToolDefinition
    {
        return $this->definition;
    }

    public function getContext(): ?array
    {
        return $this->context;
    }

    public function toArray(): array
    {
        return [
            'message' => $this->getMessage(),
            'code' => $this->getCode(),
            'definition_id' => $this->definition?->getId(),
            'definition_name' => $this->definition?->getName(),
            'context' => $this->context,
        ];
    }
}
