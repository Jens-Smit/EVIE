<?php
// src/Mcp/Toolbox/McpRemoteToolMetadata.php

namespace App\Mcp\Toolbox;

use Symfony\AI\Agent\Toolbox\Tool\ToolInterface;

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
}