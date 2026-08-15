# Threat Model

## Betrachtete Angreifer/Fehler

| Bedrohung | Beschreibung | Mitigation |
|-----------|-------------|------------|
| **Prompt Injection** | RAG-Kontext enthält "Ignore previous instructions" | SecurityGuard ist unabhängig vom RAG-Kontext; Policy-Entscheidung kann nicht umgangen werden |
| **SSRF** | Tool ruft private IP/localhost auf | `SecurityGuard::isUrlSafe()` blockt 127.0.0.1, localhost, private IPv4/IPv6, link-local (169.254), 0.0.0.0 |
| **Path Traversal** | Tool liest /etc/passwd, /proc, /sys | `SecurityGuard::isPathSafe()` blockt sensitive Pfade |
| **Symlink Escape** | Tool folgt Symlink aus Sandbox | Pfad-Sandbox blockt /etc, /root, /var, /proc, /sys, /dev |
| **Command Injection** | Executor-Type `shell`/`bash` | Executor-Whitelist: nur api/database/filesystem/http/generic |
| **Malicious Tool** | ToolDefinition mit gefährlichem Executor | `SecurityGuard::decide()` prüft Executor-Type → Deny |
| **Rogue Tool** | Nicht genehmigtes Tool wird ausgeführt | `HitlListener` blockiert pending Tools via `deny()` |
| **Tenant Data Leakage** | Tenant A sieht Daten von Tenant B | `UserContext` + `StoreRetrieverAdapter` mit user_identifier-Filter |
| **IDOR** | User ruft fremde Tool-Approval auf | `/api/tools/*` erfordert `ROLE_ADMIN` (access_control) |
| **Credential Leakage** | API-Keys in Logs/Antworten | Secrets in `.env`, nie geloggt |
| **Privilege Escalation** | Normaler User wird Admin | Role-Hierarchy: `ROLE_ADMIN` → `ROLE_USER`, Rollen vor Login gesetzt |

## Security Layers

```text
User Input
    ↓
Authentication (Symfony Security Bundle)
    ↓
Tenant Context (UserContext — user_identifier)
    ↓
Agent (Symfony AI Agent + InputProcessor)
    ↓
Tool Definition (ToolDefinition — executorType, securityLevel, requiresHitl)
    ↓
Security Classification
    ↓
SecurityGuard.decide(ToolCall, ToolDefinition) → PolicyDecision
    ↓
┌──────────────────────────────────────────┐
│  ALLOW  → Tool wird ausgeführt           │
│  ASK_USER → HITL-Freigabe erforderlich   │
│  DENY   → Ausführung blockiert + Audit  │
└──────────────────────────────────────────┘
    ↓
Executor (GenericApi/File/Database/Http)
    ↓
Audit Log (AuditLogger + AgentHistory + DecisionLog)
```

## Tests

`SecurityHardeningTest` deckt alle Vektoren ab:
- SSRF: 127.0.0.1, localhost, 169.254.169.254, 10.x, 192.168.x, 172.16.x, 0.0.0.0, ::1, fe80::, fc00::
- Filesystem: /etc/passwd, /var/run/docker.sock, /proc, /sys, /dev, /root, /var/run
- Command: shell/bash executor → Deny
- HITL: high security → AskUser, requiresHitl → AskUser
- Prompt Injection: RAG-Kontext kann Policy nicht umgehen
