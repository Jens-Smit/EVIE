<?php
// src/Event/PendingToolApprovalEvent.php

namespace App\Event;

use App\Entity\ToolDefinition;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Event, das ausgelöst wird, wenn ein neues Tool generiert wurde und auf Freigabe wartet.
 */
final class PendingToolApprovalEvent extends Event
{
    public const NAME = 'ai.tool.pending_approval';

    public function __construct(
        private ToolDefinition $toolDefinition,
        private string $userIdentifier,
    ) {
    }

    public function getToolDefinition(): ToolDefinition
    {
        return $this->toolDefinition;
    }

    public function getUserIdentifier(): string
    {
        return $this->userIdentifier;
    }
}
