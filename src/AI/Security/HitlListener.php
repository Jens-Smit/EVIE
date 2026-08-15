<?php

declare(strict_types=1);

namespace App\AI\Security;

use App\Entity\ToolDefinition;
use App\Event\PendingToolApprovalEvent;
use App\Repository\ToolDefinitionRepository;
use App\Security\UserContext;
use Symfony\AI\Agent\Toolbox\Event\ToolCallRequested;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Native Human-in-the-Loop Listener fuer EVIE (Blueprint §4.D).
 *
 * EventSubscriber auf das native Symfony AI `ToolCallRequested`-Event. KEIN
 * handgeschriebener Tool-Decorator. Die Entscheidung laeuft ueber die
 * SecurityGuard-Policy (Allow / Deny / AskUser):
 *
 *  - Allow:   Listener greift nicht ein, das Tool wird ausgefuehrt.
 *  - Deny:    $event->deny() blockiert die Ausfuehrung (Policy-Verletzung).
 *  - AskUser: ToolDefinition wird auf "pending" gesetzt, ein
 *             PendingToolApprovalEvent wird versandt und die Ausfuehrung via
 *             $event->deny() blockiert, bis der User im Frontend freigibt.
 *
 * P0-5 Tenant-Isolation: die Definition-Suche erfolgt pro Tenant, sodass
 * ein User nicht die Tools eines anderen Tenants freigeben/ablehnen kann.
 *
 * P0-9 Observability: jede Policy-Entscheidung wird im Audit-Log
 * nachvollziehbar aufgezeichnet (inkl. redigierter Tool-Argumente).
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
        private readonly UserContext $userContext,
        private readonly AuditLogger $auditLogger,
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

    public function __invoke(ToolCallRequested $event): void
    {
        $toolCall = $event->getToolCall();
        $definition = $this->findDefinition($toolCall);

        // Dynamische Tools muessen als approved ToolDefinition vorliegen; fehlt
        // die Definition, faellt die Entscheidung an die SecurityGuard-Policy.
        if (null !== $definition && 'approved' !== $definition->getStatus()) {
            $this->requestApproval($event, $definition);

            return;
        }

        $decision = $this->securityGuard->decide($toolCall, $definition);
        $decisionLabel = $this->decisionLabel($decision);

        if (PolicyDecision::Allow === $decision) {
            $this->audit('ALLOW', $toolCall, null);

            return;
        }

        if (PolicyDecision::Deny === $decision) {
            $event->deny('Tool durch SecurityGuard-Policy blockiert.');
            $this->audit('DENY', $toolCall, 'Tool durch SecurityGuard-Policy blockiert.');

            return;
        }

        // AskUser: dynamisches Tool ohne persistierte Definition oder explizit
        // als HITL markiert. Freigabe via Frontend + PendingToolApprovalEvent.
        if (null === $definition) {
            // Ohne persistierte ToolDefinition kann keine Freigabe erfolgen;
            // der Guard hat AskUser nur fuer bekannte dynamische Tools zurueckgegeben.
            $event->deny('Tool erfordert Freigabe, ist aber nicht registriert.');
            $this->audit('DENY', $toolCall, 'Tool erfordert Freigabe, ist aber nicht registriert.');

            return;
        }

        $this->requestApproval($event, $definition);
        $this->audit('ASK_USER', $toolCall, sprintf('Tool "%s" wartet auf Freigabe (HITL).', $definition->getName()));
    }

    /**
     * Findet die ToolDefinition fuer einen ToolCall, tenant-isoliert (P0-5).
     *
     * Ist ein User eingeloggt, wird nur nach dessen Tools (oder System-Tools
     * ohne Tenant-Bezug) gesucht. So kann ein User nicht die Tools eines
     * anderen Tenants freigeben oder ablehnen.
     */
    private function findDefinition(ToolCall $toolCall): ?ToolDefinition
    {
        try {
            $userIdentifier = $this->userContext->getUserIdentifier();
            if (null !== $userIdentifier) {
                return $this->toolDefinitionRepository
                    ->findOneByNameForUser($toolCall->getName(), $userIdentifier);
            }

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

    /**
     * Schreibt eine Policy-Entscheidung ins Audit-Log (P0-9).
     *
     * Tool-Argumente werden vor dem Logging redigiert, sodass Secrets,
     * API-Keys und Passwoerter niemals im Audit-Log landen.
     *
     * @param array<string|int, mixed> $arguments
     */
    private function audit(string $decision, ToolCall $toolCall, ?string $reason = null): void
    {
        try {
            $user = null;
            $token = $this->tokenStorage->getToken();
            if (null !== $token) {
                $user = $token->getUser();
            }

            $this->auditLogger->logPolicyDecision(
                $toolCall->getName(),
                $decision,
                \is_object($user) && $user instanceof \Symfony\Component\Security\Core\User\UserInterface ? $user : null,
                $toolCall->getArguments(),
                $reason
            );
        } catch (\Throwable) {
            // Audit-Logging darf den Agent-Flow nie blockieren.
        }
    }

    private function decisionLabel(PolicyDecision $decision): string
    {
        return match ($decision) {
            PolicyDecision::Allow => 'ALLOW',
            PolicyDecision::Deny => 'DENY',
            PolicyDecision::AskUser => 'ASK_USER',
        };
    }
}
