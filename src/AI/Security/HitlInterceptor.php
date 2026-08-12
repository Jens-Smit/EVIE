<?php

namespace App\AI\Security;

use App\Entity\ToolDefinition;
use App\Event\PendingToolApprovalEvent;
use Symfony\AI\Agent\Tool\ToolInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * HitlInterceptor - Implementiert Human-in-the-Loop für Tool-Ausführungen
 * 
 * Decorator-Pattern für Tool-Aufrufe:
 * 1. Prüft, ob das Tool genehmigt ist (HITL)
 * 2. Prüft, ob das Tool sicher ist (SecurityGuard)
 * 3. Führt das Tool aus oder blockiert es
 * 
 * @see https://symfony.com/doc/current/ai/bundles/ai-bundle.html
 */
#[Autoconfigure(tags: ['ai.security.interceptor'])]
final readonly class HitlInterceptor
{
    private EventDispatcherInterface $eventDispatcher;
    private SecurityGuard $securityGuard;

    public function __construct(
        EventDispatcherInterface $eventDispatcher,
        SecurityGuard $securityGuard,
    ) {
        $this->eventDispatcher = $eventDispatcher;
        $this->securityGuard = $securityGuard;
    }

    /**
     * Intercepts tool execution and checks for approval and security.
     * 
     * @param ToolInterface|ToolDefinition $tool Das Tool oder ToolDefinition
     * @param string $prompt Der User-Prompt
     * @param string $userIdentifier Der User-Identifier
     * @return array Status-Informationen
     */
    public function interceptToolExecution(object $tool, string $prompt, string $userIdentifier): array
    {
        // 1. Extrahiere ToolDefinition
        $toolDefinition = $this->getToolDefinition($tool);
        
        // 2. Prüfe SecurityGuard-Whitelist
        $toolSchema = $toolDefinition->getSchema();
        try {
            $this->securityGuard->assertToolAllowed($toolSchema, $toolDefinition->getName());
        } catch (\RuntimeException $e) {
            return [
                'status' => 'blocked',
                'reason' => 'Tool not allowed by SecurityGuard',
                'tool' => $toolDefinition->getName(),
                'error' => $e->getMessage(),
                'action' => 'security_violation',
            ];
        }

        // 3. Prüfe, ob das Tool genehmigt ist (HITL)
        if (!$toolDefinition->isApproved()) {
            // Dispatch pending approval event
            $event = new PendingToolApprovalEvent($toolDefinition, $prompt, $userIdentifier);
            $this->eventDispatcher->dispatch($event);

            return [
                'status' => 'blocked',
                'reason' => 'Tool not approved',
                'tool' => $toolDefinition->getName(),
                'action' => 'pending_approval',
            ];
        }

        // 4. Tool ist genehmigt und sicher - Ausführung erlaubt
        return [
            'status' => 'approved',
            'tool' => $toolDefinition->getName(),
            'message' => 'Tool execution allowed',
        ];
    }

    /**
     * Extrahiere die ToolDefinition aus einem Tool-Objekt.
     * 
     * @param object $tool Tool-Objekt oder ToolDefinition
     * @return ToolDefinition
     * @throws \RuntimeException Falls das Tool keine gültige Definition hat
     */
    private function getToolDefinition(object $tool): ToolDefinition
    {
        // Falls es bereits eine ToolDefinition ist
        if ($tool instanceof ToolDefinition) {
            return $tool;
        }

        // Falls das Tool eine getDefinition()-Methode hat
        if (method_exists($tool, 'getDefinition')) {
            return $tool->getDefinition();
        }

        // Falls das Tool eine getToolDefinition()-Methode hat
        if (method_exists($tool, 'getToolDefinition')) {
            return $tool->getToolDefinition();
        }

        // Falls das Tool selbst eine ToolDefinition ist (z. B. DynamicTool)
        if (property_exists($tool, 'toolDefinition') && 
            $tool->toolDefinition instanceof ToolDefinition) {
            return $tool->toolDefinition;
        }

        throw new \RuntimeException(sprintf(
            'Tool of class "%s" does not have a valid ToolDefinition.',
            get_class($tool)
        ));
    }

    /**
     * Prüft, ob ein Tool sicher ist (SecurityGuard + HITL).
     * 
     * @param ToolInterface|ToolDefinition $tool Das Tool
     * @param string $prompt Der User-Prompt
     * @param string $userIdentifier Der User-Identifier
     * @return bool True, wenn das Tool sicher und genehmigt ist
     */
    public function isToolSafe(object $tool, string $prompt, string $userIdentifier): bool
    {
        $result = $this->interceptToolExecution($tool, $prompt, $userIdentifier);
        return $result['status'] === 'approved';
    }

    /**
     * Gibt den Status eines Tools zurück.
     * 
     * @param ToolInterface|ToolDefinition $tool Das Tool
     * @param string $prompt Der User-Prompt
     * @param string $userIdentifier Der User-Identifier
     * @return string Der Status ('approved', 'blocked', 'pending_approval')
     */
    public function getToolStatus(object $tool, string $prompt, string $userIdentifier): string
    {
        $result = $this->interceptToolExecution($tool, $prompt, $userIdentifier);
        return $result['status'];
    }

    /**
     * Gibt die Blockierungsgründe zurück.
     * 
     * @param ToolInterface|ToolDefinition $tool Das Tool
     * @param string $prompt Der User-Prompt
     * @param string $userIdentifier Der User-Identifier
     * @return string|null Der Grund oder null, wenn das Tool erlaubt ist
     */
    public function getBlockReason(object $tool, string $prompt, string $userIdentifier): ?string
    {
        $result = $this->interceptToolExecution($tool, $prompt, $userIdentifier);
        return $result['status'] === 'approved' ? null : ($result['reason'] ?? 'Unknown reason');
    }
}
