<?php

namespace App\AI\Security;

use App\Entity\ToolDefinition;
use App\Event\PendingToolApprovalEvent;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[Autoconfigure(tags: ['ai.security.interceptor'])]
class HitlInterceptor
{
    private EventDispatcherInterface $eventDispatcher;

    public function __construct(EventDispatcherInterface $eventDispatcher)
    {
        $this->eventDispatcher = $eventDispatcher;
    }

    /**
     * Intercepts tool execution and checks for approval.
     */
    public function interceptToolExecution(object $tool, string $prompt, string $userIdentifier): array
    {
        // Get the tool definition
        $toolDefinition = $this->getToolDefinition($tool);

        // Check if the tool is approved
        if (!$toolDefinition->isApproved()) {
            // Dispatch pending approval event
            $event = new PendingToolApprovalEvent($toolDefinition, $prompt, $userIdentifier);
            $this->eventDispatcher->dispatch($event);

            // Return halt execution response
            return [
                'status' => 'blocked',
                'reason' => 'Tool not approved',
                'tool' => $toolDefinition->getName(),
                'action' => 'pending_approval',
            ];
        }

        // Tool is approved, proceed with execution
        return [
            'status' => 'approved',
            'tool' => $toolDefinition->getName(),
        ];
    }

    /**
     * Extracts the ToolDefinition from a tool instance.
     */
    private function getToolDefinition(object $tool): ToolDefinition
    {
        if (method_exists($tool, 'getDefinition')) {
            return $tool->getDefinition();
        }

        throw new \RuntimeException('Tool does not have a getDefinition method.');
    }
}
