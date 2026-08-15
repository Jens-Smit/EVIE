<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * User-/Tenant-Context (P1-7).
 *
 * Hält den aktuellen User-Identifier für Tenant-Isolation in
 * ToolDefinitions, RAG, Memory, AgentHistory und Audit-Logs.
 *
 * Alle Repository-Queries und Store-Abfragen sollen diesen Context
 * nutzen, um Datenlecks zwischen Tenants zu verhindern.
 */
final class UserContext
{
    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * Liefert den User-Identifier des aktuellen Requests (Tenant-Isolation).
     * Falls kein User eingeloggt ist, wird null zurückgegeben.
     */
    public function getUserIdentifier(): ?string
    {
        $request = $this->requestStack->getMainRequest();
        if (null === $request) {
            return null;
        }

        $user = $request->getUser();
        if (null !== $user && method_exists($user, 'getUserIdentifier')) {
            return $user->getUserIdentifier();
        }

        // Fallback: Security-Token über Session
        return $request->attributes->get('_evie_user_identifier');
    }

    public function setUserIdentifier(string $identifier): void
    {
        $request = $this->requestStack->getMainRequest();
        if (null !== $request) {
            $request->attributes->set('_evie_user_identifier', $identifier);
        }
    }
}
