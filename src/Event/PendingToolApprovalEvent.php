<?php

namespace App\Event;

use App\Entity\ToolDefinition;
use Symfony\Contracts\EventDispatcher\Event;

class PendingToolApprovalEvent extends Event
{
    public const NAME = 'pending_tool_approval';

    public function __construct(
        private ToolDefinition $toolDefinition,
        private string $prompt,
        private string $userIdentifier
    ) {
    }

    public function getToolDefinition(): ToolDefinition
    {
        return $this->toolDefinition;
    }

    public function getPrompt(): string
    {
        return $this->prompt;
    }

    public function getUserIdentifier(): string
    {
        return $this->userIdentifier;
    }
}
