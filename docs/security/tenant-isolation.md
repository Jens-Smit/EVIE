# Tenant Isolation

## Zentrale Frage

**Kann Tenant A Daten von Tenant B sehen?** — Nein.

## Architektur

```text
Request
   ↓
Authenticated User
   ↓
UserContext (RequestStack-basiert)
   ↓
Tenant/User Identifier
   ↓
Repository / Store (server-side filtering)
```

## UserContext

Der `UserContext`-Service (`src/Security/UserContext.php`) extrahiert den
User-Identifier aus dem aktuellen Request. Er ist als Service in
`config/services.yaml` registriert und wird in Repository-Queries und
Store-Abfragen verwendet.

## Isolation pro Komponente

| Komponente | Isolation-Mechanismus |
|------------|----------------------|
| ToolDefinitions | `ToolDefinitionRepository` (per User filterbar) |
| RAG | `StoreRetrieverAdapter` mit `user_identifier` in Options/Metadata |
| Memory | `ContextMemoryProvider` lädt kontext per `user_identifier` |
| AgentHistory | `AgentHistoryRepository::findByUserIdentifier()` |
| Audit Logs | `AuditLogger` protokolliert mit User-Referenz |
| MCP | Server-Konfiguration ist global; Tool-Ausführung durchläuft SecurityGuard |

## Beispiel

```text
Tenant A
 ├── Tool A (nur für A approved)
 ├── Memory A (UserContext: user_a)
 ├── Documents A (user_identifier: user_a)
 └── Embeddings A (metadata: user_identifier=user_a)

Tenant B
 ├── Tool B (nur für B approved)
 ├── Memory B (UserContext: user_b)
 ├── Documents B (user_identifier: user_b)
 └── Embeddings B (metadata: user_identifier=user_b)
```

## Test-Beweis

`TenantIsolationTest` verifiziert, dass der `StoreRetrieverAdapter`
unterschiedliche Ergebnisse für unterschiedliche `user_identifier` liefert.
