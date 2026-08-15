# Data-Flow: Vollständiger Request-Trace

## Beispiel: Tool-Generierung

```text
User: "Erstelle mir ein Tool, das Wetterdaten abfragt."
  ↓
AgentDialogController.ask(userMessage, userIdentifier)
  ↓
OrchestratorDialogService → Agent::call(MessageBag)
  ↓
ContextInjector.processInput() → RAG-Kontext (falls vorhanden)
ContextMemoryProvider.load() → User-Präferenzen
  ↓
Mistral LLM → "Kein passendes Tool vorhanden"
  ↓
ToolDefinitionGenerator.generateFromUserRequest()
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
