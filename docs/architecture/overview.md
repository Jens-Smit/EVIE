# Architektur-Übersicht

> EVIE folgt dem nativen Symfony AI v0.12 Agent-Loop: Der `Agent` wrappt ein
> Modell mit einer `Toolbox` und `InputProcessor`/`OutputProcessor`, ruft Tools
> auf, liest Ergebnisse und entscheidet das weitere Vorgehen — bis die Aufgabe
> erledigt ist. EVIE erweitert diesen Loop **nur** an nativen Erweiterungspunkten.

## Schichten

```text
┌─────────────────────────────────────────────────────────┐
│                      User / UI                           │
├─────────────────────────────────────────────────────────┤
│  Controller (AgentDialog, HTMX, ToolApproval, McpServer)│
├─────────────────────────────────────────────────────────┤
│  Agent / Orchestrator (Symfony AI Agent)                │
│  ├── InputProcessor: ContextInjector (RAG)              │
│  ├── InputProcessor: ContextMemoryProvider (Memory)     │
│  ├── Toolbox: DynamicToolbox (Decorator)                │
│  │   ├── Statische Tools (#[AsTool])                    │
│  │   ├── Subagents (native Subagent)                    │
│  │   └── Dynamische Tools (ToolDefinition → Tool)       │
│  └── ToolFactory: ChainFactory (MCP + Reflection)       │
├─────────────────────────────────────────────────────────┤
│  Security Layer                                          │
│  ├── HitlListener (ToolCallRequested-Event)             │
│  ├── SecurityGuard (PolicyDecision: Allow/Deny/AskUser) │
│  └── AuditLogger (AgentHistory, DecisionLog)            │
├─────────────────────────────────────────────────────────┤
│  Executor Layer                                          │
│  ├── GenericApiExecutor    GenericFileExecutor          │
│  ├── GenericDatabaseExecutor  GenericHttpExecutor       │
│  └── McpToolExecutor (MCP via ChainFactory)            │
├─────────────────────────────────────────────────────────┤
│  Infrastructure                                          │
│  ├── PostgreSQL + pgvector (Store)                      │
│  ├── Messenger (async Tool-Ausführung, Streaming)      │
│  └── ObservabilityListener (Request-ID/Trace-ID)         │
└─────────────────────────────────────────────────────────┘
```

## Native Symfony AI Erweiterungspunkte

| Erweiterungspunkt | Native Klasse | EVIE-Nutzung |
|-------------------|---------------|--------------|
| Dynamic Toolbox | `ToolboxInterface`-Decorator | `DynamicToolbox` (mergt statische + dynamische Tools) |
| HITL | `ToolCallRequested`-Event | `HitlListener` + `SecurityGuard`-Policy |
| Subagents | `Subagent` als Tool | `SubAgentFactory` → `EvieToolboxFactory` |
| Runtime Tool Parameters | `Tool` aus `ToolDefinition.schema` | `DynamicToolbox::getTools()` |
| Structured Output | Platform `outputStructure` | `ToolDefinitionGenerator` nutzt `Agent::call()` |
| RAG | `InputProcessorInterface` | `ContextInjector` + `StoreRetrieverAdapter` |
| Memory | `MemoryProviderInterface` | `ContextMemoryProvider` |
| MCP | `ToolFactory`/`ChainFactory` | `McpToolFactory` in `EvieToolboxFactory` |
