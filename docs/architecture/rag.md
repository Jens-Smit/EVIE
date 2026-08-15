# RAG (Retrieval-Augmented Generation)

## Architektur

```text
Document
  ↓
Chunking / Embedding (MistralEmbeddingService)
  ↓
Vector Store (PostgreSQL + pgvector)
  ↓
Metadata (content_type, user_identifier)
  ↓
Tenant Filter (UserContext)
  ↓
Retriever (StoreRetrieverAdapter → native RetrieverInterface)
  ↓
ContextInjector (native InputProcessor)
  ↓
SystemMessage (Message::forSystem)
  ↓
Agent
```

## Komponenten

### ContextInjector (InputProcessor)
Der `ContextInjector` implementiert `Symfony\AI\Agent\InputProcessorInterface`
mit `#[AsInputProcessor]`. Bei jedem User-Prompt:
1. Query aus der letzten User-Nachricht ableiten.
2. Über den `Retriever` relevante Kontext-Informationen abrufen.
3. Kontext als `SystemMessage` (`Message::forSystem`) in den `MessageBag` einfügen.

### StoreRetrieverAdapter
Bridget EVIEs `Retriever` zur nativen `Symfony\AI\Store\RetrieverInterface`.
Konvertiert `RetrievedItem` in native `VectorDocument`-Objekte (mit `NullVector`
+ `Metadata`).

### Tenant-Filtering
Jede Retrieval-Anfrage kann einen `user_identifier` in den Options enthalten.
Der `StoreRetrieverAdapter` injiziert diesen in die Metadata — Tenant A erhält
niemals Kontext von Tenant B.

## Prompt-Injection-Schutz

RAG-Kontext erhält **nie** die gleiche Vertrauensstufe wie System-Instructions:
- Der `ContextInjector` fügt Kontext als separate `SystemMessage` hinzu.
- Der `SecurityGuard` ist **unabhängig** vom RAG-Kontext — ein Tool-Aufruf mit
  SSRF-URL wird blockiert, selbst wenn der RAG-Kontext "Ignore previous
  instructions" enthält.
- Getestet in `SecurityHardeningTest::testRagContextCannotBypassSsrfCheck`.
