<?php

declare(strict_types=1);

namespace App\AI\Pipeline\Goal;

/**
 * Value-Object: Das uebergeordnete Ziel eines Pipeline-Laufs.
 *
 * Phase 1 (Goal) liefert dieses Objekt. Es bleibt rein deskriptiv und
 * enthaelt keine Logik. Eine Capability-Generierung oder HITL wird
 * ausschliesslich in Phase 4 ausgeloest, nie in Phase 1.
 *
 * @see docs/architecture/orchestrator-pipeline.md Phase 1
 */
final class Goal
{
    public const SOURCE_AGENT_GOAL = 'agent_goal';
    public const SOURCE_AD_HOC = 'ad_hoc';

    private string $identifier;
    private string $description;
    private ?string $successMetric;
    private string $source;

    public function __construct(
        string $identifier,
        string $description,
        ?string $successMetric,
        string $source = self::SOURCE_AD_HOC
    ) {
        $this->identifier = $identifier;
        $this->description = $description;
        $this->successMetric = $successMetric;
        $this->source = $source;
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getSuccessMetric(): ?string
    {
        return $this->successMetric;
    }

    public function getSource(): string
    {
        return $this->source;
    }
}
