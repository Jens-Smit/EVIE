<?php

namespace App\AI\Security;

use App\Entity\AuditLog;
use App\Repository\AuditLogRepository;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\User\UserInterface;
 
/**
 * AuditLogger - Logging für alle kritischen Aktionen
 */
class AuditLogger
{
    public function __construct(
        
        private AuditLogRepository $auditLogRepository,
        private RequestStack $requestStack
    ) {
    }

    /**
     * Logge eine Aktion
     */
    public function log(string $action, ?UserInterface $user, ?int $entityId, ?string $entityType, array $context = [], string $status = 'success', ?string $details = null): AuditLog
    {
        $userId = $user?->getId();
        $request = $this->requestStack->getCurrentRequest();
        
        $ipAddress = $request?->getClientIp();
        $userAgent = $request?->headers->get('User-Agent');

        return $this->auditLogRepository->log(
            $action,
            $userId,
            $entityId,
            $entityType,
            array_merge($context, [
                'user_email' => $user?->getUserIdentifier(),
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent
            ]),
            $status,
            $details
        );
    }

    /**
     * Logge Tool-Registration
     */
    public function logToolRegistration(int $toolId, string $toolName, ?UserInterface $user, bool $success, ?string $error = null): AuditLog
    {
        return $this->log(
            'tool_registration',
            $user,
            $toolId,
            'ToolDefinition',
            ['tool_name' => $toolName],
            $success ? 'success' : 'failure',
            $error
        );
    }

    /**
     * Logge Tool-Execution
     */
    public function logToolExecution(int $toolId, string $toolName, ?UserInterface $user, bool $success, ?string $error = null, array $parameters = []): AuditLog
    {
        return $this->log(
            'tool_execution',
            $user,
            $toolId,
            'ToolDefinition',
            array_merge(['tool_name' => $toolName], $parameters),
            $success ? 'success' : 'failure',
            $error
        );
    }

    /**
     * Logge HITL-Entscheidung
     */
    public function logHitlDecision(int $toolId, string $toolName, ?UserInterface $user, string $decision, ?string $reason = null): AuditLog
    {
        return $this->log(
            'hitl_decision',
            $user,
            $toolId,
            'ToolDefinition',
            ['tool_name' => $toolName, 'decision' => $decision],
            'success',
            $reason
        );
    }

    /**
     * Logge Security-Verletzung
     */
    public function logSecurityViolation(string $violationType, ?UserInterface $user, string $details, array $context = []): AuditLog
    {
        return $this->log(
            'security_violation',
            $user,
            null,
            null,
            $context,
            'failure',
            $violationType . ': ' . $details
        );
    }

    /**
     * Logge Authentifizierungsversuch
     */
    public function logAuthenticationAttempt(?UserInterface $user, bool $success, ?string $error = null, string $ipAddress = null): AuditLog
    {
        return $this->log(
            'authentication',
            $user,
            null,
            'User',
            ['ip_address' => $ipAddress],
            $success ? 'success' : 'failure',
            $error
        );
    }

    /**
     * Logge API-Aufruf
     */
    public function logApiCall(string $endpoint, ?UserInterface $user, bool $success, array $parameters = [], ?string $error = null): AuditLog
    {
        return $this->log(
            'api_call',
            $user,
            null,
            null,
            ['endpoint' => $endpoint, 'parameters' => $this->redact($parameters)],
            $success ? 'success' : 'failure',
            $error
        );
    }

    /**
     * Logge eine Policy-Entscheidung (Allow/Deny/AskUser) fuer einen ToolCall
     * (P0-9 Observability). Wird vom HitlListener aufgerufen.
     *
     * @param array<string|int, mixed> $arguments
     */
    public function logPolicyDecision(string $toolName, string $decision, ?UserInterface $user, array $arguments = [], ?string $reason = null): AuditLog
    {
        return $this->log(
            'policy_decision',
            $user,
            null,
            'ToolDefinition',
            ['tool_name' => $toolName, 'decision' => $decision, 'arguments' => $this->redact($arguments)],
            'success',
            $reason
        );
    }

    /**
     * Redigiert sensible Werte in Tool-Parametern (P0-9).
     *
     * Erkennt Schluessel wie password, secret, api_key, token, authorization
     * und ersetzt deren Werte durch '***REDACTED***'. Verschachtelte Arrays
     * werden rekursiv durchlaufen.
     *
     * @param array<string|int, mixed> $data
     *
     * @return array<string|int, mixed>
     */
    public function redact(array $data): array
    {
        $sensitiveKeys = ['password', 'secret', 'api_key', 'apikey', 'token', 'authorization', 'auth', 'private_key', 'credentials'];

        foreach ($data as $key => $value) {
            $keyLower = is_string($key) ? strtolower($key) : (string) $key;
            if (is_array($value)) {
                $data[$key] = $this->redact($value);
                continue;
            }

            foreach ($sensitiveKeys as $sensitive) {
                if (str_contains($keyLower, $sensitive)) {
                    $data[$key] = '***REDACTED***';
                    continue 2;
                }
            }
        }

        return $data;
    }
}
