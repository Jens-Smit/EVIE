# ADR-002: Self-Evolution auf Capability-Schicht

## Context
EVIE soll sich "selbst-evolvieren" — neue Fähigkeiten erlernen.

## Problem
Autonome Quellcode-Modifikation ist unsicher und nicht kontrollierbar.

## Options
1. **Autonome Code-Generierung** — EVIE schreibt PHP-Code
2. **Capability-Layer-Evolution** — EVIE generiert Tool-Definitionen (JSON-Schema)

## Decision
**Option 2:** Evolution erfolgt durch kontrollierte Generierung, Validierung,
HITL-Freigabe und Registrierung neuer ToolDefinition-Entities. Kein PHP-Code.

## Consequences
- Tools sind JSON-Schema-basiert, nicht code-basiert
- Jedes Tool durchläuft HITL + SecurityGuard
- ToolDefinition hat Status (pending/approved/rejected)
- DynamicToolbox lädt approved-Tools live aus der DB
