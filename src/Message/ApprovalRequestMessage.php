<?php

namespace App\Message;

use Symfony\Component\Uid\Ulid;

/**
 * ApprovalRequestMessage represents a request for approval of an action.
 * 
 * This message is used for Human-in-the-Loop (HITL) approval workflows.
 * When an action requires approval, this message is dispatched to notify
 * administrators or authorized users.
 */
class ApprovalRequestMessage
{
    public const string QUEUE_NAME = 'approval_requests';

    public function __construct(
        public string $approvalId,
        public string $tenantId,
        public string $userId,
        public string $action,
        public ?string $resource = null,
        public array $context = [],
        public string $riskLevel = 'medium',
        public \DateTimeImmutable $requestedAt,
        public string $status = 'pending'
    ) {
        if (empty($this->approvalId)) {
            $this->approvalId = Ulid::generate();
        }
    }

    /**
     * Create a new approval request message.
     */
    public static function create(
        string $tenantId,
        string $userId,
        string $action,
        ?string $resource = null,
        array $context = [],
        string $riskLevel = 'medium'
    ): self {
        return new self(
            approvalId: Ulid::generate(),
            tenantId: $tenantId,
            userId: $userId,
            action: $action,
            resource: $resource,
            context: $context,
            riskLevel: $riskLevel,
            requestedAt: new \DateTimeImmutable(),
            status: 'pending'
        );
    }

    /**
     * Get the approval ID.
     */
    public function getApprovalId(): string
    {
        return $this->approvalId;
    }

    /**
     * Get the tenant ID.
     */
    public function getTenantId(): string
    {
        return $this->tenantId;
    }

    /**
     * Get the user ID.
     */
    public function getUserId(): string
    {
        return $this->userId;
    }

    /**
     * Get the action.
     */
    public function getAction(): string
    {
        return $this->action;
    }

    /**
     * Get the resource.
     */
    public function getResource(): ?string
    {
        return $this->resource;
    }

    /**
     * Get the context.
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Get the risk level.
     */
    public function getRiskLevel(): string
    {
        return $this->riskLevel;
    }

    /**
     * Set the risk level.
     */
    public function setRiskLevel(string $riskLevel): static
    {
        $this->riskLevel = $riskLevel;
        return $this;
    }

    /**
     * Get the requested at timestamp.
     */
    public function getRequestedAt(): \DateTimeImmutable
    {
        return $this->requestedAt;
    }

    /**
     * Get the status.
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * Set the status.
     */
    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    /**
     * Check if the approval is pending.
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if the approval is approved.
     */
    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    /**
     * Check if the approval is rejected.
     */
    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    /**
     * Check if the approval is expired.
     */
    public function isExpired(): bool
    {
        return $this->status === 'expired';
    }

    /**
     * Convert to array for logging/debugging.
     */
    public function toArray(): array
    {
        return [
            'approvalId' => $this->approvalId,
            'tenantId' => $this->tenantId,
            'userId' => $this->userId,
            'action' => $this->action,
            'resource' => $this->resource,
            'context' => $this->context,
            'riskLevel' => $this->riskLevel,
            'requestedAt' => $this->requestedAt->format('c'),
            'status' => $this->status,
        ];
    }

    /**
     * Get a human-readable description of this approval request.
     */
    public function getDescription(): string
    {
        $resource = $this->resource ? " on {$this->resource}" : '';
        return sprintf(
            'Approval Request #%s: %s%s (Risk: %s)',
            substr($this->approvalId, 0, 8),
            $this->action,
            $resource,
            ucfirst($this->riskLevel)
        );
    }
}
