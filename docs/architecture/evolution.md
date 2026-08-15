# Self-Evolution

## Was bedeutet „Self-Evolving" bei EVIE?

**EVIE modifiziert niemals eigenen PHP-Code.** Stattdessen erfolgt Evolution auf
der **Capability-Schicht**: Der Agent erkennt, dass ein Tool fehlt, generiert ein
JSON-Schema für ein neues Tool, lässt es sicherheitsprüfen und nach menschlicher
Freigabe über die native `DynamicToolbox` registrieren.

Das ist **kontrollierte Autonomie** — nicht „EVIE programmiert sich selbst".

## Evolution-Workflow (Blueprint §5)

```text
1. Bedarfserkennung
   User: "Analysiere diese Excel-Datei und gib mir den Umsatz."

2. Fehlschlag
   Orchestrator stellt fest: kein passendes Tool in der Dynamic Toolbox.

3. Ideengenerierung
   Orchestrator → ToolDefinitionGenerator (nutzt LLM-Agent `tool_generator`).

4. Schema-Entwurf
   Mistral generiert ein JSON-Schema für "ExcelParserTool",
   das einen existierenden GenericFileExecutor nutzen soll.

5. HITL-Blockade
   Entwurf wird als ToolDefinition (status: pending) gespeichert.
   ToolCallRequested-Listener blockiert die Ausführung ($event->deny())
   und versendet ein PendingToolApprovalEvent.

6. User-Interaktion
   Frontend zeigt: "Neues Tool 'ExcelParserTool' erforderlich. Genehmigen?"

7. Freigabe
   User klickt "Ja" → ToolDefinition.status = approved.

8. Dynamische Registrierung
   DynamicToolbox liefert das Tool ab dem nächsten Agent-Call via getTools().

9. Ausführung
   Orchestrator wiederholt den Prompt, findet das Tool, führt es aus
   (nach erneutem SecurityGuard-Check via ToolCallRequested).

10. Audit
    Jede Ausführung wird in AgentHistory protokolliert.
```

## Tool-Lifecycle

```text
Generated → Pending → Validated → Approved → Available → Executed → Revoked
```

| Status | Bedeutung |
|--------|-----------|
| `pending` | Tool-Entwurf wartet auf Freigabe |
| `approved` | Tool ist freigegeben und über DynamicToolbox verfügbar |
| `rejected` | Tool wurde abgelehnt |
| `pending_approval` | HITL-Freigabe angefordert |

## Revocation

Ein approved Tool kann jederzeit auf `pending` zurückgesetzt werden
(`/api/tools/{id}/approve` mit Status-Reset). Die `DynamicToolbox` liest
approved-Tools live aus der DB — beim nächsten `getTools()`-Call ist das Tool
nicht mehr verfügbar.

## Tests

- `EvolutionFlowIntegrationTest` — vollständiger Flow: pending → blockiert →
  approved → erlaubt, plus Revoke, invalid Executor, SSRF, blocked Path,
  ASK_USER (high security), Tool-Version, invalid Schema.
