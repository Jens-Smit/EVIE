# Data-Flow: Vollständiger Request-Trace

> Hinweis: Der primäre Pfad ist die fünf-Phasen-Pipeline
> (Goal -> Intent -> Plan -> Capability -> Execution), die von
> `OrchestratorDialogService::ask()` über `PipelineInterface`
> aufgerufen wird. Der unten stehende Trace ist das entsprechende
> Beispiel aus Pipeline-Sicht; die detaillierte Phasenbeschreibung
> steht in `orchestrator-pipeline.md`. `OrchestratorDialogService`
> ist eine dünne Fassade ohne eigene Dispatch-Logik.

## Beispiel: Tool-Generierung (Pipeline-Sicht)

```text
User: "Erstelle mir ein Tool, das Wetterdaten abfragt."
  ↓
AgentDialogController.ask(userMessage, userIdentifier)
  ↓
OrchestratorDialogService → Pipeline::run()
  ↓
Phase 1 Goal  → GoalResolver (AgentGoal oder ad-hoc-LLM)
Phase 2 Intent → IntentClassifier (TASK)  → kein Dialog-Exit
Phase 3 Plan   → Planner (tool_plan, needsCapability=true)
Phase 4 Capability → CapabilityResolver → missing
  ↓
ToolDefinitionGenerator.generateToolDefinition()
  ↓
tool_generator Agent → JSON-Schema für "WeatherLookupTool"
  ↓
ToolDefinition (status: pending, executorType: http, securityLevel: medium)
  ↓
SecurityGuard.decide() → PolicyDecision::AskUser (requiresHitl=true)
  ↓
PendingToolApprovalEvent versandt
  ↓
Frontend: "Neues Tool 'WeatherLookupTool' erforderlich. Genehmigen?"
  ↓
User: "Ja" → /api/tools/{id}/approve
  ↓
ToolDefinition (status: approved)
  ↓
DynamicToolbox.getTools() → Tool verfügbar
  ↓
Orchestrator wiederholt Prompt → Tool-Call
  ↓
ToolCallRequested → HitlListener → SecurityGuard.decide() → Allow
  ↓
GenericHttpExecutor.execute() → Wetter-API-Aufruf
  ↓
Ergebnis → LLM → Antwort an User
  ↓
AuditLogger.logToolExecution() → AgentHistory
```

## Beispiel: Statisches Tool

```text
User: "Wie ist das Wetter in Berlin?"
  ↓
Agent → Tool-Call: weather({city: "Berlin"})
  ↓
ToolCallRequested → SecurityGuard → Allow (statisches Tool, keine ToolDefinition)
  ↓
WeatherTool.__invoke("Berlin") → Wetterdaten
  ↓
Ergebnis → LLM → "In Berlin sind es 24°C und sonnig."
  ↓
AuditLogger
```
