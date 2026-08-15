# Agent-Architektur

Der Orchestrator ist ein nativer `Symfony\AI\Agent\Agent`. Er **führt keine
konkreten Aufgaben aus**, sondern plant und delegiert über Tool-Calling.

## Agent-Loop

```text
User-Prompt
  ↓
InputProcessor (ContextInjector → RAG-Kontext als SystemMessage)
  ↓
InputProcessor (ContextMemoryProvider → User-Präferenzen als Memory)
  ↓
Agent.call(MessageBag)
  ↓
LLM (Mistral) → Tool-Selection
  ↓
Toolbox.getTools() → DynamicToolbox merged:
  ├── statische Tools (WeatherTool, FileReadTool, …)
  ├── Subagent-Tools (website_researcher, data_analyst, …)
  └── dynamische Tools (ToolDefinition status=approved → Tool-Objekt)
  ↓
ToolCallRequested-Event → HitlListener
  ↓
SecurityGuard.decide(ToolCall, ToolDefinition) → PolicyDecision
  ↓
┌────────────────────────────────────────────┐
│ ALLOW → Tool wird ausgeführt               │
│ ASK_USER → deny(), PendingToolApprovalEvent│
│ DENY → deny("Policy blockiert")            │
└────────────────────────────────────────────┘
  ↓
Executor führt Tool aus
  ↓
Ergebnis → LLM → nächste Iteration oder finale Antwort
```

## Konfiguration

Die Agent-Konfiguration erfolgt in `config/packages/ai.yaml`:

- `ai.agent.orchestrator` — Haupt-Agent mit statischen Tools + DynamicToolbox
- `ai.agent.tool_generator` — LLM-Agent für Tool-Schema-Generierung
- `ai.agent.onboarding` — Chat-basiertes User-Onboarding

## Sub-Agents

Sub-Agents sind native `Symfony\AI\Agent\Toolbox\Tool\Subagent`-Instanzen.
Die `SubAgentFactory` erzeugt sie und die `EvieToolboxFactory` registriert sie
als Tools in der Toolbox — der Orchestrator ruft Sub-Agents wie jedes andere
Tool auf.

Verfügbare Sub-Agents: `website_researcher`, `data_analyst`, `code_assistant`,
`document_processor`, `communication_manager`, `api_integration`,
`project_manager`, `finance_manager`, `hr_manager`, `marketing_manager`,
`ceo_assistant`.
