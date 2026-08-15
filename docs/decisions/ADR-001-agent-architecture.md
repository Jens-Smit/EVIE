# ADR-001: Native Symfony AI Agent-Architektur

## Context
EVIE benötigt einen AI-Agent-Loop, der Tools aufruft, Ergebnisse liest und iteriert.

## Problem
Eigenbau-Dispatcher (wie der frühere SubAgentDispatcher) schaffen eine parallele
Infrastruktur neben Symfony AI, sind fehleranfällig und schwer zu warten.

## Options
1. **Eigenbau-Dispatcher** — volle Kontrolle, aber Wartungsaufwand
2. **Symfony AI nativer Agent** — Standard-Loop, native Erweiterungspunkte

## Decision
**Option 2:** Nativer `Symfony\AI\Agent\Agent` mit `AgentProcessor` + `Toolbox`.
EVIE erweitert nur an nativen Erweiterungspunkten (DynamicToolbox, ToolCallRequested,
InputProcessor, ChainFactory).

## Consequences
- Keine parallele Tool-Infrastruktur
- SubAgentDispatcher (760 Zeilen) wurde entfernt
- HTMXController nutzt SubAgentFactory direkt
