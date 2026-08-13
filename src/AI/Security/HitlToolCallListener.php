<?php

namespace App\AI\Security;

use App\Event\PendingToolApprovalEvent;
use App\Repository\ToolDefinitionRepository;
use Symfony\AI\Agent\Toolbox\Event\ToolCallArgumentsResolved;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Event-Listener für HITL (Human-in-the-Loop) bei Tool-Aufrufen.
 * Prüft, ob ein Tool freigegeben ist, und blockiert die Ausführung, falls nicht.
 */
final class HitlToolCallListener
{
    public function __construct(
        private SecurityGuard $securityGuard, 
        private ToolDefinitionRepository $toolDefinitionRepo,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    /**
     * Wird aufgerufen, wenn ein Tool-Aufruf argument-resolviert ist.
     * Prüft, ob das Tool freigegeben ist.
     */
    public function __invoke(ToolCallArgumentsResolved $event): void
    {
        $toolName = $event->toolCall->name;
        $definition = $this->toolDefinitionRepo->findOneBy(['name' => $toolName]);

        if ($definition && !$definition->isApproved()) {
            $this->eventDispatcher->dispatch(
                new PendingToolApprovalEvent($definition, (string) $event->toolCall, 'system')
            );

            throw new \Symfony\AI\Agent\Toolbox\Exception\ToolExecutionException(
                sprintf('Tool "%s" wartet auf Freigabe (HITL).', $definition->getName())
            );
        }
    }
}