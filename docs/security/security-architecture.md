# Security-Architektur

## Übersicht

```text
User Input
    ↓
Authentication (LoginFormAuthenticator / Symfony Security)
    ↓
access_control (ROLE_USER / ROLE_ADMIN für /api/tools/*)
    ↓
ApiSecurityListener (loggt API-Aufrufe, prüft UserInterface)
    ↓
Tenant Context (UserContext — user_identifier aus Request)
    ↓
Agent (Symfony AI Agent + InputProcessor)
    ↓
Tool Definition (ToolDefinition — executorType, securityLevel, requiresHitl)
    ↓
SecurityGuard.decide(ToolCall, ToolDefinition) → PolicyDecision
    ↓
┌──────────────────────────────────────────┐
│  ALLOW  → Tool wird ausgeführt           │
│  ASK_USER → HITL-Freigabe erforderlich   │
│  DENY   → Ausführung blockiert + Audit  │
└──────────────────────────────────────────┘
    ↓
HitlListener (ToolCallRequested-Event)
    ↓
Executor (GenericApi/File/Database/Http)
    ↓
AuditLogger → AuditLog, AgentHistory, DecisionLog
```

## Komponenten

### SecurityGuard (`src/AI/Security/SecurityGuard.php`)
- Executor-Whitelist: `api`, `database`, `filesystem`, `http`, `generic`
- SSRF-Schutz: `isUrlSafe()` blockt private IPs, localhost, IPv6
- Pfad-Sandbox: `isPathSafe()` blockt sensitive Pfade
- Service-Whitelist: `isServiceAllowed()` (strikt, keine Wildcards)
- `decide(ToolCall, ?ToolDefinition): PolicyDecision`

### HitlListener (`src/AI/Security/HitlListener.php`)
- EventSubscriber auf `ToolCallRequested`
- `Allow` → kein Eingriff
- `Deny` → `$event->deny()`
- `AskUser` → `ToolDefinition` auf `pending`, `PendingToolApprovalEvent`, `deny()`

### ApiSecurityListener (`src/EventListener/ApiSecurityListener.php`)
- Prüft `/api/tools/*`-Routen auf authentifizierten User
- Loggt API-Aufrufe via `AuditLogger`

### ToolSecurityListener (`src/EventListener/ToolSecurityListener.php`)
- Loggt Tool-Ausführungen

## PolicyDecision

```php
enum PolicyDecision
{
    case Allow;    // Tool wird ausgeführt
    case Deny;     // Tool wird blockiert (SSRF, Pfad, Executor)
    case AskUser;  // HITL-Freigabe erforderlich
}
```
