<?php

declare(strict_types=1);

namespace App\AI\Security;

use App\Entity\ToolDefinition;
use App\Event\PendingToolApprovalEvent;
use App\Repository\ToolDefinitionRepository;
use Symfony\AI\Agent\Toolbox\Event\ToolCallRequested;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Native Human-in-the-Loop Listener für EVIE (Blueprint §4.D).
 *
 * EventSubscriber auf das native Symfony AI `ToolCallRequested`-Event. KEIN
 * handgeschriebener Tool-Decorator. Die Entscheidung läuft über die
 * SecurityGuard-Policy (Allow / Deny / AskUser):
 *
 *  - Allow:   Listener greift nicht ein, das Tool wird ausgeführt.
 *  - Deny:    $event->deny() blockiert die Ausführung (Policy-Verletzung).
 *  - AskUser: ToolDefinition wird auf "pending" gesetzt, ein
 *             PendingToolApprovalEvent wird versandt und die Ausführung via
 *             $event->deny() blockiert, bis der User im Frontend freigibt.
 *
 * @see https://symfony.com/doc/current/ai/cookbook/human-in-the-loop.html
 */
#[AsEventListener(event: ToolCallRequested::class)]
final class HitlListener
{
    public function __construct(
        private readonly SecurityGuard $securityGuard,
        private readonly ToolDefinitionRepository $toolDefinitionRepository,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function __invoke(ToolCallRequested $event): void
    {
        $toolCall = $event->getToolCall();
        $definition = $this->findDefinition($toolCall);

        // Dynamische Tools müssen als approved ToolDefinition vorliegen; fehlt
        // die Definition, fällt die Entscheidung an die SecurityGuard-Policy.
        if (null !== $definition && 'approved' !== $definition->getStatus()) {
            $this->requestApproval($event, $definition);

            return;
        }

        $decision = $this->securityGuard->decide($toolCall, $definition);

        if (PolicyDecision::Allow === $decision) {
            return;
        }

        if (PolicyDecision::Deny === $decision) {
            $event->deny('Tool durch SecurityGuard-Policy blockiert.');

            return;
        }

        // AskUser: dynamisches Tool ohne persistierte Definition oder explizit
        // als HITL markiert. Freigabe via Frontend + PendingToolApprovalEvent.
        if (null === $definition) {
            // Ohne persistierte ToolDefinition kann keine Freigabe erfolgen;
            // der Guard hat AskUser nur für bekannte dynamische Tools zurückgegeben.
            $event->deny('Tool erfordert Freigabe, ist aber nicht registriert.');

            return;
        }

        $this->requestApproval($event, $definition);
    }

    private function findDefinition(ToolCall $toolCall): ?ToolDefinition
    {
        try {
            return $this->toolDefinitionRepository
                ->findOneBy(['name' => $toolCall->getName()]);
        } catch (\Throwable) {
            return null;
        }
    }

    private function requestApproval(ToolCallRequested $event, ToolDefinition $definition): void
    {
        $definition->setStatus('pending');

        $this->eventDispatcher->dispatch(
            new PendingToolApprovalEvent(
                $definition,
                (string) json_encode($event->getToolCall()->getArguments()),
            ),
        );

        $event->deny(sprintf('Tool "%s" wartet auf Freigabe (HITL).', $definition->getName()));
    }
}
