<?php

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Repository\GoalEvaluationRepository::class)]
#[ORM\Table(name: 'goal_evaluations')]
#[ORM\Index(name: 'idx_goal_evaluation_goal', columns: ['goal_id'])]
#[ORM\Index(name: 'idx_goal_evaluation_history', columns: ['agent_history_id'])]
#[ORM\Index(name: 'idx_goal_evaluation_created', columns: ['created_at'])]
class GoalEvaluation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::INTEGER)]
    private int $goalId;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $agentHistoryId = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $success;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $score = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $feedback = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $evaluationDetails = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $evaluatedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeImmutable $createdAt = null;

    #[ORM\ManyToOne(targetEntity: AgentGoal::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private AgentGoal $goal;

    #[ORM\ManyToOne(targetEntity: AgentHistory::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?AgentHistory $agentHistory = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGoalId(): int
    {
        return $this->goalId;
    }

    public function setGoalId(int $goalId): static
    {
        $this->goalId = $goalId;
        return $this;
    }

    public function getAgentHistoryId(): ?int
    {
        return $this->agentHistoryId;
    }

    public function setAgentHistoryId(?int $agentHistoryId): static
    {
        $this->agentHistoryId = $agentHistoryId;
        return $this;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function setSuccess(bool $success): static
    {
        $this->success = $success;
        return $this;
    }

    public function getScore(): ?float
    {
        return $this->score;
    }

    public function setScore(?float $score): static
    {
        $this->score = $score;
        return $this;
    }

    public function getFeedback(): ?string
    {
        return $this->feedback;
    }

    public function setFeedback(?string $feedback): static
    {
        $this->feedback = $feedback;
        return $this;
    }

    public function getEvaluationDetails(): ?string
    {
        return $this->evaluationDetails;
    }

    public function setEvaluationDetails(?string $evaluationDetails): static
    {
        $this->evaluationDetails = $evaluationDetails;
        return $this;
    }

    public function getEvaluatedBy(): ?string
    {
        return $this->evaluatedBy;
    }

    public function setEvaluatedBy(?string $evaluatedBy): static
    {
        $this->evaluatedBy = $evaluatedBy;
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

    public function getGoal(): AgentGoal
    {
        return $this->goal;
    }

    public function setGoal(AgentGoal $goal): static
    {
        $this->goal = $goal;
        $this->goalId = $goal->getId();
        return $this;
    }

    public function getAgentHistory(): ?AgentHistory
    {
        return $this->agentHistory;
    }

    public function setAgentHistory(?AgentHistory $agentHistory): static
    {
        $this->agentHistory = $agentHistory;
        $this->agentHistoryId = $agentHistory?->getId();
        return $this;
    }
}
