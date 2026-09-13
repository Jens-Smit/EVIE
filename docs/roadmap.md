# Roadmap

**Stand:** Überarbeitet und verifiziert gegen den Code-Stand auf `main`
(ausgehend von Commit `a3abb07`, fortlaufend aktualisiert). Die Status-Angaben
beruhen auf Code-Verifikation (PHPStan, PHPUnit, PG-Migrationen); die
zugrunde liegenden Audit-Ergebnisse sind in diese Roadmap sowie in
[`docs/security/production-hardening.md`](security/production-hardening.md)
eingearbeitet.

---

## Legende

- ✅ Erledigt — Code verifiziert vorhanden/funktionierend, CI grün
- 🟡 Teilweise — implementiert, aber mit Einschränkung/ offener Teilaufgabe
- ⬜ Offen — noch nicht umgesetzt

---

## ✅ Erledigt (verifiziert gegen Code-Stand)

### Architektur & Kern (Runde 1–5)
- [x] Native Symfony AI v0.12 Architektur (Agent, Toolbox, Platform)
- [x] DynamicToolbox (ToolboxInterface-Decorator)
- [x] HitlListener (ToolCallRequested-Event)
- [x] SecurityGuard mit PolicyDecision (Allow/Deny/AskUser)
- [x] SubAgentDispatcher entfernt → native SubAgentFactory
- [x] ContextInjector als nativer InputProcessor (RAG)
- [x] Tenant-Isolation (UserContext, serverseitiger Filter auf
      Repository-Ebene via `user_identifier`)
- [x] ObservabilityListener (Request-ID/Trace-ID)
- [x] Production Dockerfile (OPcache, Healthcheck)
- [x] Vollständige Dokumentation (Architektur, Security, Development, ADRs)

### Security-Hardening (Phase 1)
- [x] SSRF-Bypass: DNS-Rebinding + IPv6-Normalisierung
      (`OutboundRequestPolicy::isPrivateIpv6()` via `inet_pton`)
- [x] Security Headers + Stack-Trace-Schutz
      (`SecurityHeadersListener`, `APP_DEBUG=0`)
- [x] Session-Fixation-Schutz (MIGRATE-Default + `cookie_httponly`)
- [x] Sensitive-Data-in-Logs (Redaction in `AuditLogger`, Controller loggt nur
      Content-Type)
- [x] Command-Chaining-/Shell-Metazeichen-Erkennung in `SecurityGuard`
- [x] MCP-Server-Argument-Validierung (`McpServerFactory`)
- [x] MCP-Verbindungen: Timeout, Retry, Authentifizierung (`McpServerManager`)

### Code-Qualität & Performance (Phase 2)
- [x] Vektor-Suche-Kandidatenmenge per SQL filtern
      (`EmbeddingRepository::loadCandidates()`, JSON-Tenant-Filter)
- [x] Embedding-Cache (`VectorStore::findCachedByContentHash()`, cache.app)
- [x] Connection Pooling / HTTP/2 / Compression (Nginx-Upstream keepalive,
      Doctrine persistent, Gzip)

### CI/CD & Test (Phase 3)
- [x] Security Scan (`composer audit --no-dev`) als CI-Step
- [x] Coverage-Reporting (pcov + Clover-Report + Artefakt-Upload)
- [x] PHPStan-Gate (Baseline zeilengenau, keine klassenweiten Ignores mehr für
      `Security`/`Rag`/`Skills`)

### Dokumentation (Phase 4)
- [x] API-Doku (`docs/api/overview.md`)
- [x] Architektur-Doku (`docs/architecture/*`)
- [x] Deployment-Anleitung (`docs/deployment/production.md`)
- [x] Prod-Ready-Nachweis (`docs/security/production-hardening.md`)

### Phase 2 Features
- [x] Sub-Agenten dynamisch (DB-gestützt, CompilerPass)
- [x] Streaming-Antworten (Messenger + Session-Verwaltung)
- [x] MCP-Server dynamisch konfigurierbar (DB + SecurityGuard)
- [x] Frontend HTMX/Alpine.js (Endpoints + Templates)

### P0 — Migrations-Bootstrap & CI
- [x] **P0-A — Baseline-Migration** erstellt (`migrations/Version20260811000000.php`):
      legt das vollständige finale Schema (alle Tabellen aus den Entities:
      `users`, `user_profile`, `sub_agent`, `agent_history`, `document`,
      `decision_log`, `tool_category`, `tool_definitions`, `audit_logs`,
      `embeddings`, `reset_password_request`, `ai_sub_agent_definitions`,
      `ai_mcp_server_definitions`, `ai_streaming_sessions`) inkl. FK-Constraints
      in korrekter Reihenfolge und pgvector-Erweiterung an. Alte inkrementelle
      Migrationskette (14 Dateien, historisch inkonsistent: MySQL-ismen,
      `REFERENCES user` statt `users`, `tool_definition` vs. `tool_definitions`)
      nach `migrations_archived/` verschoben — Doctrine lädt nur `migrations/`.
      **Verifikation:** CI `migrations`-Job spielt die Kette gegen leere PG-DB ab.
- [x] **P0-B — CI auf `migrations:migrate` umgestellt** (`.github/workflows/ci.yml`):
      der `migrations`-Job führt nun `doctrine:migrations:migrate` (statt
      bisher `doctrine:schema:create`) gegen eine frische leere
      PostgreSQL-15+pgvector-Instanz aus. `doctrine:schema:validate` bleibt als
      In-Sync-Check erhalten. Beweist, dass das Repo von Grund auf
      bootstrappbar ist (Quick-Start aus README).

### P1 — API-Drift & Runtime-Fehler
- [x] **P1-A — RAG nutzt nativen pgvector** (`src/Repository/EmbeddingRepository.php`):
      `findSimilar()` hat einen PostgreSQL-nativen pgvector-Pfad, der die
      Cosine-Distanz serverseitig via `<=>`-Operator berechnet (`ORDER BY
      vector::text::vector <=> :query::vector ASC`) — statt alle Vektoren nach
      PHP zu laden. JSON-gespeicherte Vektoren werden per `::text::vector`-
      Cast in den pgvector-Typ gewandelt. Tenant-Filter bleibt serverseitig.
      Der SQLite-Fallback (Tests) nutzt weiterhin die PHP-basierte
      `cosineSimilarity()`-Berechnung, da SQLite keinen pgvector-Typ kennt.
- [x] **P1-B — WorkflowOrchestrator undefinierte Methoden** (`src/AI/Workflow/WorkflowOrchestrator.php`,
      `HitlWorkflowManager.php`): API-Drift korrigiert — Aufrufe an die
      tatsächlich existierenden Methoden angepasst: `generateFromRequest` →
      `generateToolDefinition`, `createFromDefinition` → `createAndRegisterTool`,
      `getError` → `getErrorMessage`, `injectForSystemPrompt` → `inject`.
      `DynamicTool::getDefinition()` (existiert nicht) durch null/Tool-Namen
      ersetzt (DynamicTool ist ein DTO ohne Entity-Referenz).
- [x] **P1-C — Weitere Baseline-Methodenfehler** (`GenericToolExecutor.php`,
      `HTMXController.php`): `SecurityGuard::validateToolConfiguration()` durch
      `containsShellMetacharacters()`-Parameter-Check ersetzt.
      `HTMXController` nutzt jetzt `DynamicToolFactory` (injiziert) für
      Tool-Liste (`getAllTools`) und Tool-Lookup (`getTool`) + `DynamicToolExecutor`
      für Ausführung mit Tool-Objekt (statt nicht-existierendem
      `execute(string)`/`getAvailableTools()`). Behebt echten HTTP-Laufzeitfehler.
- [x] **P1-D — GHCR-Publishing + Docker-Smoke-Test** (`.github/workflows/docker.yml`):
      neuer Workflow baut das Prod-Image (`docker/php/Dockerfile.prod`), pusht es zu GHCR (`ghcr.io/jens-smit/evie:latest` + SHA-Tag) bei main-Pushes, und führt danach einen Smoke-Test aus (Image starten, Boot-Status prüfen). Läuft nur bei main-Pushes und `workflow_dispatch`.

### P2 — Aufräumarbeiten
- [x] **P2-A — PgVectorStore toter Code entfernt** (`src/Infrastructure/VectorStore/PgVectorStore.php` + `VectorStoreInterface.php` gelöscht): Die Klasse war nicht konsumiert, nutzte MySQL-Syntax (`ORDER BY RAND()`), eine nicht-existente Tabelle (`vector_embeddings`) und einen „simplified version"-Platzhalter. P1-A hat den echten pgvector-Pfad in `EmbeddingRepository` implementiert, damit ist diese Klasse obsolet. `VectorStoreInterface` wurde von niemandem injiziert.
- [x] **P2-F — Structured Output** über native Symfony-AI-Mechanismen umgesetzt.
- [x] **P3-A — Golden-Path-Test** auf `WebTestCase` umgestellt inkl. Tool-Ausführung.
- [x] **P3-D — Orchestrierungs-Konsolidierung** hat die Schichten von 5 auf 3 reduziert.

---

## ⬜ Offen — P1 (vor Production adressieren)

Keine offenen P1-Blocker.

---

## ⬜ Offen — P2 (mittelfristig)

Keine offenen P2-Punkte.

---

## ⬜ Offen — P3 (technische Schuld / Aufräumarbeiten)

Aktuell keine dokumentierten P3-Punkte.

---

## ⏳ Geplant (Features, nicht Prod-blockierend)

- [ ] Distributed Messenger Workers (Multi-Node-Skalierung über die
      bestehenden docker-compose-Worker-Container hinaus)
- [ ] Advanced Scheduling (Cron-basierte Agent-Tasks)
- [ ] LLM-Latency/Token-Usage-Metriken (Observability-Erweiterung)
- [ ] Monitoring & Observability (ELK/Loki-Stack)
- [ ] Skalierbarkeit (PgBouncer-Sidecar, Container-Skalierung)
- [ ] Erweiterte Sicherheit (CSP mit HTMX-Frontend, pgvector-Typ-Migration)
- [ ] AI-Features (Orchestrierungs-Konsolidierung fortsetzen)

---

## CI-Status (zusammenfasst)

| Suite | Status |
|-------|--------|
| Unit / Security / Skills / Agent / Functional | ✅ grün |
| Integration / E2E / E2E Smoke / Golden Path | ✅ grün |
| PHPStan | ✅ grün, **keine** Baseline mehr (7 Methodenfehler in P1-B/C behoben) |
| Composer validate / audit | ✅ |
| **Migrations-Job** | ✅ `doctrine:migrations:migrate` gegen leere PG-DB (P0-B) |
| **Docker (GHCR + Smoke-Test)** | ✅ (P1-D) |

> **Netto-Bewertung:** P0, P1, P2 und P3 sind weitgehend abgearbeitet.
> CI ist grün (Tests + Migrations + PHPStan ohne Baseline + Docker). Alle
> Roadmap-Punkte sind erledigt; verbleibende Punkte sind nicht
> Prod-blockierende Feature-Wünsche.
