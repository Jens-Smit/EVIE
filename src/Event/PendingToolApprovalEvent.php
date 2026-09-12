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

    /**
     * @param string|null $userIdentifier Tenant-Identifier des anfragenden Users
     *     (null im CLI-Kontext oder wenn kein Security-Token verfuegbar ist).
     * @param bool $approved true bei Freigabe, false bei Ablehnung / wartet
     */
    public function __construct(
        private ToolDefinition $toolDefinition,
        private ?string $userIdentifier = null,
        private bool $approved = false,
    ) {
    }

    public function getToolDefinition(): ToolDefinition
    {
        return $this->toolDefinition;
    }

    public function getUserIdentifier(): ?string
    {
        return $this->userIdentifier;
    }

    public function isApproved(): bool
    {
        return $this->approved;
    }
}
