<?php
// src/Mcp/Toolbox/McpRemoteToolMetadata.php

namespace App\Mcp\Toolbox;

use Symfony\AI\Agent\Toolbox\Tool\ToolInterface;
use Symfony\AI\Platform\Tool\ExecutionReference;

final class McpRemoteToolMetadata implements ToolInterface
{
    public function __construct(
        private readonly string $name,
        private readonly string $description,
        private readonly array $inputSchema,
        private readonly string $serverAlias,
        private readonly string $remoteName,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getInputSchema(): array
    {
        return $this->inputSchema;
    }

    public function getServerAlias(): string
    {
        return $this->serverAlias;
    }

    public function getRemoteName(): string
    {
        return $this->remoteName;
    }

    public function getExecutionReference(): ExecutionReference
    {
        return new ExecutionReference(
            McpToolExecutor::class,
            'execute',
            [
                'serverAlias' => $this->serverAlias,
                'toolName' => $this->remoteName,
            ]
        );
    }
}
