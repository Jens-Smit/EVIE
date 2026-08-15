# ADR-003: Security-Modell mit PolicyDecision

## Context
Jede Tool-Ausführung muss sicherheitsgeprüft werden.

## Problem
Boolesche Rückgabewerte (true/false) können keine HITL-Freigabe abbilden.

## Options
1. **Bool-Rückgabe** — einfach, aber kein HITL möglich
2. **PolicyDecision-Enum** — Allow/Deny/AskUser

## Decision
**Option 2:** `PolicyDecision`-Enum (Allow/Deny/AskUser). Der `SecurityGuard::decide()`
prüft Executor-Whitelist, SSRF, Pfad-Sandbox, securityLevel und requiresHitl.

## Consequences
- HitlListener auf natives ToolCallRequested-Event
- deny() blockiert Ausführung, PendingToolApprovalEvent für HITL
- SecurityHardeningTest deckt alle Angriffsvektoren ab
