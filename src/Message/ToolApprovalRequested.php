<?php

namespace App\Message;

class ToolApprovalRequested
{
    public function __construct(
        private int $toolId,
        private string $toolName,
        private string $description,
        private array $schema,
        private string $requestedBy
    ) {
    }

    public function getToolId(): int
    {
        return $this->toolId;
    }

    public function getToolName(): string
    {
        return $this->toolName;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getSchema(): array
    {
        return $this->schema;
    }

    public function getRequestedBy(): string
    {
        return $this->requestedBy;
    }
}