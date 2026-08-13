<?php

namespace AppEntity;

use DoctrineDBALTypesTypes;
use DoctrineORMMapping as ORM;

#[ORMEntity(repositoryClass: AuditLogRepository::class)]
#[ORMTable(name: 'audit_logs')]
#[ORMIndex(name: 'idx_audit_user', columns: ['user_id'])]
#[ORMIndex(name: 'idx_audit_action', columns: ['action'])]
#[ORMIndex(name: 'idx_audit_created', columns: ['created_at'])]
class AuditLog
{
    #[ORMId]
    #[ORMGeneratedValue]
    #[ORMColumn]
    private ?int $id = null;

    #[ORMColumn(type: Types::STRING, length: 100)]
    private ?string $action = null; // 'tool_execution', 'tool_registration', 'hitl_approval', 'security_violation'

    #[ORMColumn(type: Types::STRING, length: 255, nullable: true)]
    private ?string $entityType = null; // 'ToolDefinition', 'User', 'DynamicTool'

    #[ORMColumn(type: Types::INTEGER, nullable: true)]
    private ?int $entityId = null;

    #[ORMColumn(type: Types::INTEGER, nullable: true)]
    private ?int $userId = null;

    #[ORMColumn(type: Types::TEXT, nullable: true)]
    private ?string $details = null;

    #[ORMColumn(type: Types::JSON, nullable: true)]
    private ?array $context = null;

    #[ORMColumn(type: Types::STRING, length: 50, nullable: true)]
    private ?string $ipAddress = null;

    #[ORMColumn(type: Types::STRING, length: 500, nullable: true)]
    private ?string $userAgent = null;

    #[ORMColumn(type: Types::STRING, length: 50, nullable: true)]
    private ?string $status = null; // 'success', 'failure', 'warning'

    #[ORMColumn(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }

    // Getter und Setter
    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAction(): ?string
    {
        return $this->action;
    }

    public function setAction(string $action): static
    {
        $this->action = $action;
        return $this;
    }

    public function getEntityType(): ?string
    {
        return $this->entityType;
    }

    public function setEntityType(?string $entityType): static
    {
        $this->entityType = $entityType;
        return $this;
    }

    public function getEntityId(): ?int
    {
        return $this->entityId;
    }

    public function setEntityId(?int $entityId): static
    {
        $this->entityId = $entityId;
        return $this;
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }

    public function setUserId(?int $userId): static
    {
        $this->userId = $userId;
        return $this;
    }

    public function getDetails(): ?string
    {
        return $this->details;
    }

    public function setDetails(?string $details): static
    {
        $this->details = $details;
        return $this;
    }

    public function getContext(): ?array
    {
        return $this->context;
    }

    public function setContext(?array $context): static
    {
        $this->context = $context;
        return $this;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function setIpAddress(?string $ipAddress): static
    {
        $this->ipAddress = $ipAddress;
        return $this;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function setUserAgent(?string $userAgent): static
    {
        $this->userAgent = $userAgent;
        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    /**
     * Konvertiere zu Array für Logging
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'user_id' => $this->userId,
            'details' => $this->details,
            'context' => $this->context,
            'ip_address' => $this->ipAddress,
            'user_agent' => $this->userAgent,
            'status' => $this->status,
            'created_at' => $this->createdAt?->format('Y-m-d H:i:s'),
        ];
    }
}
