<?php

namespace AppAIWorkflow;

use AppEntityUser;
use AppAISkillsToolDynamicTool;

/**
 * DTO für blockierte Executions
 */
class PendingExecution
{
    private string $executionId;
    private DynamicTool $tool;
    private array $parameters;
    private User $user;
    private string $originalRequest;
    private DateTimeImmutable $createdAt;

    public function __construct(
        string $executionId,
        DynamicTool $tool,
        array $parameters,
        User $user,
        string $originalRequest
    ) {
        $this->executionId = $executionId;
        $this->tool = $tool;
        $this->parameters = $parameters;
        $this->user = $user;
        $this->originalRequest = $originalRequest;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getExecutionId(): string
    {
        return $this->executionId;
    }

    public function getTool(): DynamicTool
    {
        return $this->tool;
    }

    public function getParameters(): array
    {
        return $this->parameters;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getOriginalRequest(): string
    {
        return $this->originalRequest;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function toArray(): array
    {
        return [
            'execution_id' => $this->executionId,
            'tool_name' => $this->tool->getName(),
            'user_id' => $this->user->getId(),
            'user_email' => $this->user->getUserIdentifier(),
            'original_request' => $this->originalRequest,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'parameters' => $this->parameters
        ];
    }
}
