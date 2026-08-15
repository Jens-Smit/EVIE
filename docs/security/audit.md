# Audit Logging

## Architektur

Jede Tool-Ausführung wird in `AgentHistory` protokolliert (wer, was, wann,
Ergebnis). Bei HITL-relevanten Aktionen zusätzlich in `DecisionLog`.

```text
Tool-Ausführung
  ↓
AuditLogger.logToolExecution(toolId, toolName, user, success, error, parameters)
  ↓
AuditLog-Entity (action, user, entityId, entityType, context, status)
  ↓
AgentHistory (input, output, status, userProfile)
```

## AuditLogger-Methoden

| Methode | Zweck |
|---------|------|
| `log()` | Allgemeine Audit-Einträge |
| `logToolRegistration()` | Tool-Registrierung |
| `logToolExecution()` | Tool-Ausführung (mit Parametern) |
| `logHitlDecision()` | HITL-Entscheidung (approve/reject) |
| `logSecurityViolation()` | Sicherheitsverstoß |
| `logAuthenticationAttempt()` | Login-Versuch |
| `logApiCall()` | API-Aufruf |

## Integration

- `ApiSecurityListener` — loggt jeden `/api/*`-Aufruf
- `ToolSecurityListener` — loggt Tool-Ausführung
- `HitlWorkflowManager` — loggt HITL-Blockaden und Freigaben

## Entities

| Entity | Tabelle | Zweck |
|--------|---------|-------|
| `AuditLog` | `audit_logs` | Allgemeine Audit-Einträge |
| `AgentHistory` | `agent_history` | Dialogverläufe |
| `DecisionLog` | `decision_logs` | HITL-Entscheidungen |
