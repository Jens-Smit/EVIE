<?php

namespace App\AI\Skills\Tool;

use App\Entity\ToolDefinition;
use Symfony\AI\Agent\Tool\ToolInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTool;

/**
 * DynamicTool - Implementiert ToolInterface für dynamisch generierte Tools
 * 
 * Diese Klasse ermöglicht die Ausführung von Tools, die zur Laufzeit
 * aus ToolDefinition-Entities generiert werden.
 * 
 * @see https://symfony.com/doc/current/ai/bundles/ai-bundle.html#register-tools
 */
#[AsTool]
final readonly class DynamicTool implements ToolInterface
{
    public function __construct(
        private ToolDefinition $toolDefinition,
        private DynamicToolExecutor $executor,
    ) {
    }

    /**
     * Führt das Tool aus.
     * 
     * @param mixed ...$arguments Argumente für das Tool
     * @return mixed Das Ergebnis der Tool-Ausführung
     */
    public function __invoke(...$arguments): mixed
    {
        return $this->executor->execute($this->toolDefinition, $arguments);
    }

    /**
     * Gibt den Namen des Tools zurück.
     */
    public function getName(): string
    {
        return $this->toolDefinition->getName();
    }

    /**
     * Gibt die Beschreibung des Tools zurück.
     */
    public function getDescription(): string
    {
        return $this->toolDefinition->getDescription();
    }

    /**
     * Gibt das Schema des Tools zurück.
     */
    public function getSchema(): array
    {
        return $this->toolDefinition->getSchema();
    }

    /**
     * Gibt die ToolDefinition zurück.
     */
    public function getToolDefinition(): ToolDefinition
    {
        return $this->toolDefinition;
    }

    /**
     * Gibt die Parameter des Tools zurück.
     */
    public function getParameters(): array
    {
        return $this->toolDefinition->getParameters() ?? [];
    }

    /**
     * Prüft, ob das Tool genehmigt ist.
     */
    public function isApproved(): bool
    {
        return $this->toolDefinition->isApproved();
    }

    /**
     * Gibt den Status des Tools zurück.
     */
    public function getStatus(): string
    {
        return $this->toolDefinition->getStatus();
    }
}
