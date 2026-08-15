<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;

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
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

    /**
     * Liefert den User-Identifier des aktuellen Requests (Tenant-Isolation).
     * Falls kein User eingeloggt ist, wird null zurückgegeben.
     *
     * Primäre Quelle ist der Security-Token (TokenStorage), der bei einem
     * Session-Login den echten User repräsentiert. Request::getUser()
     * liefert dagegen nur den PHP_AUTH_USER-Header (HTTP-Basic) und ist bei
     * normalem Form-/Session-Login praktisch immer null — das hätte die
     * Tenant-Filterung im HitlListener wirkungslos gemacht (P0-5).
     */
    public function getUserIdentifier(): ?string
    {
        $token = $this->tokenStorage->getToken();
        if (null !== $token) {
            $user = $token->getUser();
            if ($user instanceof UserInterface) {
                return $user->getUserIdentifier();
            }
        }

        // Fallback für nicht-HTTP-Kontexte (z. B. Messenger-Worker), in
        // denen kein Security-Token existiert: explizit am Request gesetzter
        // Identifier (siehe setUserIdentifier()).
        $request = $this->requestStack->getMainRequest();
        if (null !== $request) {
            return $request->attributes->get('_evie_user_identifier');
        }

        return null;
    }

    public function setUserIdentifier(string $identifier): void
    {
        $request = $this->requestStack->getMainRequest();
        if (null !== $request) {
            $request->attributes->set('_evie_user_identifier', $identifier);
        }
    }
}
