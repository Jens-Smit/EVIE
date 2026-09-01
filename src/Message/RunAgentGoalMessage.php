<?php

namespace App\Message;

use Symfony\Component\Uid\Uuid;

/**
 * Message für die autonome Ausführung eines Agent-Ziels.
 * Wird vom RunAgentGoalsCommand an den async_agent_goals Transport gesendet
 * und vom RunAgentGoalHandler verarbeitet.
 */
class RunAgentGoalMessage
{
    private Uuid $messageId;
    private int $goalId;
    private string $userIdentifier;
    private string $goalTitle;
    private ?array $capabilityConstraints;
    private \DateTimeImmutable $createdAt;

    public function __construct(
        int $goalId,
        string $userIdentifier,
        string $goalTitle,
        ?array $capabilityConstraints = null
    ) {
        $this->messageId = Uuid::v4();
        $this->goalId = $goalId;
        $this->userIdentifier = $userIdentifier;
        $this->goalTitle = $goalTitle;
        $this->capabilityConstraints = $capabilityConstraints;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getMessageId(): Uuid
    {
        return $this->messageId;
    }

    public function getGoalId(): int
    {
        return $this->goalId;
    }

    public function getUserIdentifier(): string
    {
        return $this->userIdentifier;
    }

    public function getGoalTitle(): string
    {
        return $this->goalTitle;
    }

    public function getCapabilityConstraints(): ?array
    {
        return $this->capabilityConstraints;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Convert to array for logging/serialization
     */
    public function toArray(): array
    {
        return [
            'message_id' => $this->messageId->toRfc4122(),
            'goal_id' => $this->goalId,
            'user_identifier' => $this->userIdentifier,
            'goal_title' => $this->goalTitle,
            'capability_constraints' => $this->capabilityConstraints,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
        ];
    }
}
