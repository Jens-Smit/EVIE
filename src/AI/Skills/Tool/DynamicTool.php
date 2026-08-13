<?php

namespace App\AI\Skills\Tool;

use Symfony\AI\Platform\Tool\Tool;

/**
 * DynamicTool - Erweitert um executorType, executorConfig, securityPolicy, hitlPolicy, version
 */
class DynamicTool extends Tool
{
    private ?string $executorType;
    private array $executorConfig;
    private array $securityPolicy;
    private array $hitlPolicy;
    private string $version;

    public function __construct(
        string $name,
        ?string $description = null,
        array $schema = [],
        ?string $executorType = null,
        array $executorConfig = [],
        array $securityPolicy = [],
        array $hitlPolicy = [],
        string $version = '1.0'
    ) {
        parent::__construct($name, $description, $schema);
        
        $this->executorType = $executorType;
        $this->executorConfig = $executorConfig;
        $this->securityPolicy = $securityPolicy;
        $this->hitlPolicy = $hitlPolicy;
        $this->version = $version;
    }

    public function getExecutorType(): ?string
    {
        return $this->executorType;
    }

    public function getExecutorConfig(): array
    {
        return $this->executorConfig;
    }

    public function getSecurityPolicy(): array
    {
        return $this->securityPolicy;
    }

    public function getHitlPolicy(): array
    {
        return $this->hitlPolicy;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    /**
     * Gibt die vollständige Konfiguration als Array zurück
     */
    public function toArray(): array
    {
        return [
            'name' => $this->getName(),
            'description' => $this->getDescription(),
            'schema' => $this->getSchema(),
            'executorType' => $this->executorType,
            'executorConfig' => $this->executorConfig,
            'securityPolicy' => $this->securityPolicy,
            'hitlPolicy' => $this->hitlPolicy,
            'version' => $this->version,
        ];
    }

    /**
     * Prüft ob das Tool HITL erfordert
     */
    public function requiresHitl(): bool
    {
        return ($this->hitlPolicy['requiresApproval'] ?? false) === true;
    }

    /**
     * Prüft ob der Executor sicher ist
     */
    public function isSafeExecutor(): bool
    {
        $allowedExecutors = ['api', 'database', 'filesystem', 'http'];
        return in_array($this->executorType, $allowedExecutors, true);
    }
}